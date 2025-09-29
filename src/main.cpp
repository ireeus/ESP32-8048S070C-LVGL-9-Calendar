#include "display.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <time.h>
#include <Preferences.h>
#include <Update.h>
#include <WiFiClientSecure.h>

// Forward declarations
void fetchEvents();
void updateEventDisplay(lv_obj_t *calendar);
void wifi_connect_cb(lv_event_t *e);
void api_code_submit_cb(lv_event_t *e);
void wifi_logout_cb(lv_event_t *e);
void api_logout_cb(lv_event_t *e);
void keyboard_event_cb(lv_event_t *e);
void show_wifi_setup_screen();
void show_api_code_screen();
void setup_calendar();
void button_event_cb(lv_event_t *e);
void calendar_event_cb(lv_event_t *e);
void new_event_btn_cb(lv_event_t *e);
void new_event_submit_cb(lv_event_t *e);
void new_event_cancel_cb(lv_event_t *e);
void checkFirmwareUpdate();
void updateFirmware();
void update_btn_cb(lv_event_t *e);
void printMemoryUsage();
void updateDateTimeLabel(); // New function to update date-time label

// Preferences for WiFi, API code, and firmware version
Preferences preferences;

// LVGL objects
static lv_obj_t *wifi_setup_screen = nullptr;
static lv_obj_t *api_code_screen = nullptr;
static lv_obj_t *new_event_popup = nullptr;
static lv_obj_t *keyboard = nullptr;
static lv_obj_t *date_time_label = nullptr; // Renamed from month_label
static lv_obj_t *eventContainer = nullptr;
static lv_obj_t *calendar = nullptr;
static lv_obj_t *update_btn = nullptr;
static lv_obj_t *version_label = nullptr;
static lv_obj_t *update_popup = nullptr;
static lv_obj_t *update_status_label = nullptr;

// Structure to hold new event UI elements
struct NewEventUI {
  lv_obj_t *title_ta;
  lv_obj_t *desc_ta;
  lv_obj_t *start_year_dd;
  lv_obj_t *start_month_dd;
  lv_obj_t *start_day_dd;
  lv_obj_t *start_hour_dd;
  lv_obj_t *start_min_dd;
  lv_obj_t *end_year_dd;
  lv_obj_t *end_month_dd;
  lv_obj_t *end_day_dd;
  lv_obj_t *end_hour_dd;
  lv_obj_t *end_min_dd;
};

// Event structure
struct Event {
  String summary;
  String start;
  String end;
  String description;
  bool isToday;
  bool isAllDay;
};

Event events[300];
int numEvents = 0;

// Store unique dates for highlighting
struct EventDate {
  int year;
  int month;
  int day;
};
EventDate eventDates[1000];
int numEventDates = 0;

// Time zone for London (BST/GMT)
const char* ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 0;
const int daylightOffset_sec = 3600;

// WiFi and API code
String ssid;
String password;
String apiCode;

// OTA variables
String currentFirmwareVersion = "1.0.0"; // Loaded from Preferences in setup()
String latestFirmwareVersion = "";
String firmwareUrl = "";
WiFiClientSecure client;

// Debounce for touch events
static unsigned long lastEventTime = 0;
const unsigned long debounceDelay = 200;

// Auto-refresh timer
static unsigned long lastRefreshTime = 0;
const unsigned long refreshInterval = 60000;

// Timer for updating date-time label
static unsigned long lastDateTimeUpdate = 0;
const unsigned long dateTimeUpdateInterval = 1000; // Update every 1 second

// Helper function to print memory usage
void printMemoryUsage() {
  Serial.printf("[DEBUG] Free heap: %d bytes, Free PSRAM: %d bytes\n",
                heap_caps_get_free_size(MALLOC_CAP_8BIT),
                heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
}

void fetchEvents() {
  Serial.println("[APP] Starting event fetch...");
  printMemoryUsage();
  
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[APP] WiFi not connected");
    return;
  }

  Serial.println("[APP] Starting HTTP request...");
  HTTPClient http;
  String url = "https://breezbee.co.uk/api/api.php?code=" + apiCode;
  http.begin(url);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    Serial.println("[APP] Response: " + payload);

    StaticJsonDocument<2048> doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      Serial.println("[APP] JSON parsing failed: " + String(error.c_str()));
      return;
    }

    JsonArray eventArray = doc["events"];
    numEvents = 0;
    numEventDates = 0;

    if (eventArray.isNull()) {
      Serial.println("[APP] No events found in response");
      return;
    }

    Serial.println("[APP] Processing " + String(eventArray.size()) + " events");
    for (JsonObject eventObj : eventArray) {
      if (numEvents >= 300) {
        Serial.println("[APP] Event limit reached (300)");
        break;
      }

      events[numEvents].summary = eventObj["summary"].as<String>();
      events[numEvents].start = eventObj["start"].as<String>();
      events[numEvents].end = eventObj["end"].as<String>();
      events[numEvents].description = eventObj["description"].as<String>();

      if (events[numEvents].start.isEmpty() || events[numEvents].end.isEmpty()) {
        Serial.println("[APP] Event " + String(numEvents) + " has empty start/end, skipping");
        continue;
      }

      events[numEvents].isAllDay = (events[numEvents].start.endsWith("00:00:00") && 
                                   (events[numEvents].end.endsWith("23:59:00") || 
                                    events[numEvents].end.endsWith("23:59:59")));

      Serial.println("[APP] Event " + String(numEvents) + ":");
      Serial.println("  Summary: " + events[numEvents].summary);
      Serial.println("  Start: " + events[numEvents].start);
      Serial.println("  End: " + events[numEvents].end);
      Serial.println("  Description: " + events[numEvents].description);
      Serial.println("  AllDay: " + String(events[numEvents].isAllDay ? "Yes" : "No"));

      String startDate = events[numEvents].start;
      String endDate = events[numEvents].end;
      if (startDate.length() > 10) startDate = startDate.substring(0, 10);
      if (endDate.length() > 10) endDate = endDate.substring(0, 10);

      int startYear, startMonth, startDay;
      int endYear, endMonth, endDay;
      if (sscanf(startDate.c_str(), "%d-%d-%d", &startYear, &startMonth, &startDay) != 3 ||
          sscanf(endDate.c_str(), "%d-%d-%d", &endYear, &endMonth, &endDay) != 3) {
        Serial.println("[APP] Failed to parse dates for event " + String(numEvents) + ": " + startDate + " to " + endDate);
        continue;
      }

      struct tm start_tm = {0};
      start_tm.tm_year = startYear - 1900;
      start_tm.tm_mon = startMonth - 1;
      start_tm.tm_mday = startDay;
      struct tm end_tm = {0};
      end_tm.tm_year = endYear - 1900;
      end_tm.tm_mon = endMonth - 1;
      end_tm.tm_mday = endDay;

      time_t start_time = mktime(&start_tm);
      time_t end_time = mktime(&end_tm);
      if (start_time == -1 || end_time == -1) {
        Serial.println("[APP] Invalid time conversion for event " + String(numEvents));
        continue;
      }

      if (start_time > end_time) {
        Serial.println("[APP] Invalid date range: start > end, swapping");
        time_t temp = start_time;
        start_time = end_time;
        end_time = temp;
      }

      time_t current_time = start_time;
      while (current_time <= end_time && numEventDates < 1000) {
        struct tm *tm = localtime(&current_time);
        if (tm->tm_year + 1900 < 1970 || tm->tm_year + 1900 > 2030) {
          Serial.println("[APP] Invalid highlight date: " + String(tm->tm_year + 1900) + "-" + 
                         String(tm->tm_mon + 1) + "-" + String(tm->tm_mday) + ", skipping");
          current_time += 86400;
          continue;
        }
        eventDates[numEventDates].year = tm->tm_year + 1900;
        eventDates[numEventDates].month = tm->tm_mon + 1;
        eventDates[numEventDates].day = tm->tm_mday;
        Serial.println("[APP] Added highlight date: " + 
                       String(eventDates[numEventDates].year) + "-" + 
                       String(eventDates[numEventDates].month) + "-" + 
                       String(eventDates[numEventDates].day));
        numEventDates++;
        current_time += 86400;
      }

      time_t now;
      time(&now);
      struct tm *nowTm = localtime(&now);
      time_t todayStart = mktime(nowTm);
      todayStart -= todayStart % 86400;
      events[numEvents].isToday = (start_time >= todayStart && start_time < todayStart + 86400);
      Serial.println("[APP] Event isToday: " + String(events[numEvents].isToday ? "Yes" : "No"));

      numEvents++;
    }

    Serial.println("[APP] Fetched " + String(numEvents) + " events, " + String(numEventDates) + " highlight dates");
  } else {
    Serial.println("[APP] HTTP request failed: " + String(httpCode));
    return;
  }

  http.end();
  printMemoryUsage();
}

bool isDateHighlightable(int year, int month, int day) {
  for (int i = 0; i < numEventDates; i++) {
    if (eventDates[i].year == year && eventDates[i].month == month && eventDates[i].day == day) {
      Serial.println("[APP] Date " + String(year) + "-" + String(month) + "-" + String(day) + " is highlightable");
      return true;
    }
  }
  Serial.println("[APP] Date " + String(year) + "-" + String(month) + "-" + String(day) + " is NOT highlightable");
  return false;
}

void updateEventDisplay(lv_obj_t *calendar) {
  Serial.println("[APP] Updating event display...");
  printMemoryUsage();

  if (!eventContainer) {
    eventContainer = lv_obj_create(lv_scr_act());
    lv_obj_set_size(eventContainer, 400, 350);
    lv_obj_align(eventContainer, LV_ALIGN_TOP_RIGHT, -10, 50);
    lv_obj_set_style_bg_color(eventContainer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_width(eventContainer, 0, 0);
    lv_obj_set_scrollbar_mode(eventContainer, LV_SCROLLBAR_MODE_AUTO);
    Serial.println("[APP] Created eventContainer");
  } else {
    lv_obj_clean(eventContainer);
  }

  Serial.println("[APP] Setting highlighted dates...");
  lv_calendar_date_t highlighted_dates[1000];
  int highlight_count = 0;
  for (int i = 0; i < numEventDates && highlight_count < 1000; i++) {
    highlighted_dates[highlight_count].year = eventDates[i].year;
    highlighted_dates[highlight_count].month = eventDates[i].month;
    highlighted_dates[highlight_count].day = eventDates[i].day;
    Serial.println("[APP] Highlighting date: " + 
                   String(highlighted_dates[highlight_count].year) + "-" + 
                   String(highlighted_dates[highlight_count].month) + "-" + 
                   String(highlighted_dates[highlight_count].day));
    highlight_count++;
  }
  if (calendar) {
    lv_calendar_set_highlighted_dates(calendar, highlighted_dates, highlight_count);
    Serial.println("[APP] Highlighted " + String(highlight_count) + " dates");
  } else {
    Serial.println("[APP] Error: Calendar object is null in updateEventDisplay");
  }

  int y_offset = 10;
  int displayed = 0;
  for (int i = 0; i < numEvents; i++) {
    if (events[i].isToday) {
      lv_obj_t *eventLabel = lv_label_create(eventContainer);
      String summary = events[i].summary;
      if (summary.length() > 55) summary = summary.substring(0, 55); // Limit title to 55 characters
      String description = events[i].description;
      if (description.length() > 55) description = description.substring(0, 55); // Limit description to 55 characters
      String timeText = events[i].isAllDay ? "All day" : 
                        "from " + events[i].start.substring(11, 16) + " to " + events[i].end.substring(11, 16);
      String text = summary + "\n" + timeText;
      if (description.length() > 0) {
        text += "\n" + description;
      }
      text += "\n...";
      lv_label_set_text(eventLabel, text.c_str());
      lv_obj_set_style_text_font(eventLabel, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(eventLabel, lv_color_hex(0xFFFFFF), 0);
      lv_obj_set_style_text_align(eventLabel, LV_TEXT_ALIGN_LEFT, 0);
      lv_obj_align(eventLabel, LV_ALIGN_TOP_LEFT, 10, y_offset);
      y_offset += 80; // Increased to accommodate extra lines
      displayed++;
    }
  }

  lv_obj_t *upcomingLabel = lv_label_create(eventContainer);
  lv_label_set_text(upcomingLabel, "                           --- Upcoming ---");
  lv_obj_set_style_text_font(upcomingLabel, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(upcomingLabel, lv_color_hex(0xFFFFFF), 0);
  lv_obj_align(upcomingLabel, LV_ALIGN_TOP_LEFT, 10, y_offset);
  y_offset += 25;

  int upcomingCount = 0;
  time_t now;
  time(&now);
  struct tm *nowTm = localtime(&now);
  time_t todayStart = mktime(nowTm);
  todayStart -= todayStart % 86400;
  for (int i = 0; i < numEvents; i++) {
    if (!events[i].isToday) {
      String startDate = events[i].start;
      if (startDate.length() > 10) startDate = startDate.substring(0, 10);
      struct tm timeinfo = {0};
      int year, month, day;
      if (sscanf(startDate.c_str(), "%d-%d-%d", &year, &month, &day) == 3) {
        timeinfo.tm_year = year - 1900;
        timeinfo.tm_mon = month - 1;
        timeinfo.tm_mday = day;
        time_t eventTime = mktime(&timeinfo);
        if (eventTime >= todayStart) {
          lv_obj_t *eventLabel = lv_label_create(eventContainer);
          String summary = events[i].summary;
          if (summary.length() > 55) summary = summary.substring(0, 55); // Limit title to 55 characters
          String description = events[i].description;
          if (description.length() > 55) description = description.substring(0, 55); // Limit description to 55 characters
          String timeText = events[i].isAllDay ? "All day" : 
                            "from " + events[i].start.substring(11, 16) + " to " + events[i].end.substring(11, 16);
          String text = summary + "\n" + 
                        startDate + ", " + timeText;
          if (description.length() > 0) {
            text += "\n" + description;
          }
          text += "\n...";
          lv_label_set_text(eventLabel, text.c_str());
          lv_obj_set_style_text_font(eventLabel, &lv_font_montserrat_14, 0);
          lv_obj_set_style_text_color(eventLabel, lv_color_hex(0xFFFFFF), 0);
          lv_obj_set_style_text_align(eventLabel, LV_TEXT_ALIGN_LEFT, 0);
          lv_obj_align(eventLabel, LV_ALIGN_TOP_LEFT, 10, y_offset);
          y_offset += 80; // Increased to accommodate extra lines
          upcomingCount++;
        }
      }
    }
  }

  if (displayed == 0 && upcomingCount == 0) {
    lv_obj_t *noEventsLabel = lv_label_create(eventContainer);
    lv_label_set_text(noEventsLabel, "No events");
    lv_obj_set_style_text_font(noEventsLabel, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(noEventsLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_align(noEventsLabel, LV_ALIGN_TOP_LEFT, 10, y_offset);
  }
  Serial.println("[APP] Event display updated: " + String(displayed) + " today, " + String(upcomingCount) + " upcoming");
}

void wifi_connect_cb(lv_event_t * e) {
  Serial.println("[APP] WiFi connect button clicked");
  lv_obj_t *dropdown = (lv_obj_t *)lv_event_get_user_data(e);
  lv_obj_t *password_ta = lv_obj_get_child(wifi_setup_screen, 1);

  char selected_ssid[32];
  lv_dropdown_get_selected_str(dropdown, selected_ssid, sizeof(selected_ssid));
  String ssid_str = String(selected_ssid);
  String password_str = String(lv_textarea_get_text(password_ta));

  Serial.println("[APP] Connecting to WiFi: " + ssid_str);
  WiFi.begin(ssid_str.c_str(), password_str.c_str());
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("[APP] WiFi connected! IP: " + WiFi.localIP().toString());
    preferences.begin("wifi", false);
    preferences.putString("ssid", ssid_str);
    preferences.putString("password", password_str);
    preferences.end();
    Serial.println("[APP] WiFi credentials saved");

    lv_obj_add_flag(wifi_setup_screen, LV_OBJ_FLAG_HIDDEN);
    preferences.begin("api", false);
    apiCode = preferences.getString("apiCode", "");
    preferences.end();
    if (apiCode == "") {
      show_api_code_screen();
    } else {
      setup_calendar();
    }
  } else {
    Serial.println("[APP] WiFi connection failed");
    lv_obj_t *error_label = lv_label_create(wifi_setup_screen);
    lv_label_set_text(error_label, "Connection failed");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 200);
  }
}

void api_code_submit_cb(lv_event_t * e) {
  Serial.println("[APP] API code submit button clicked");
  lv_obj_t *api_ta = (lv_obj_t *)lv_event_get_user_data(e);
  apiCode = String(lv_textarea_get_text(api_ta));

  preferences.begin("api", false);
  preferences.putString("apiCode", apiCode);
  preferences.end();
  Serial.println("[APP] API code saved");

  lv_obj_add_flag(api_code_screen, LV_OBJ_FLAG_HIDDEN);
  setup_calendar();
}

void wifi_logout_cb(lv_event_t * e) {
  Serial.println("[APP] WiFi logout button clicked");
  preferences.begin("wifi", false);
  preferences.clear();
  preferences.end();
  Serial.println("[APP] WiFi credentials cleared");
  WiFi.disconnect();
  show_wifi_setup_screen();
}

void api_logout_cb(lv_event_t * e) {
  Serial.println("[APP] API logout button clicked");
  preferences.begin("api", false);
  preferences.clear();
  preferences.end();
  Serial.println("[APP] API code cleared");
  show_api_code_screen();
}

void keyboard_event_cb(lv_event_t * e) {
  lv_event_code_t code = lv_event_get_code(e);
  lv_obj_t *ta = (lv_obj_t *)lv_event_get_user_data(e);
  if (code == LV_EVENT_FOCUSED) {
    if (ta && keyboard) {
      lv_keyboard_set_textarea(keyboard, ta);
      lv_obj_clear_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    } else {
      Serial.println("[APP] Error: Invalid textarea or keyboard in keyboard_event_cb");
    }
  } else if (code == LV_EVENT_DEFOCUSED) {
    if (keyboard) {
      lv_keyboard_set_textarea(keyboard, nullptr);
      lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    }
  }
}

void show_wifi_setup_screen() {
  Serial.println("[APP] Showing WiFi setup screen...");
  wifi_setup_screen = lv_obj_create(lv_scr_act());
  lv_obj_set_size(wifi_setup_screen, 800, 480);
  lv_obj_set_style_bg_color(wifi_setup_screen, lv_color_hex(0x000000), 0);

  int n = WiFi.scanNetworks();
  Serial.println("[APP] Found " + String(n) + " networks");
  String ssid_list = "";
  for (int i = 0; i < n && i < 10; i++) {
    ssid_list += WiFi.SSID(i);
    if (i < n - 1) ssid_list += "\n";
  }

  lv_obj_t *dropdown = lv_dropdown_create(wifi_setup_screen);
  lv_dropdown_set_options(dropdown, ssid_list.c_str());
  lv_obj_set_width(dropdown, 300);
  lv_obj_align(dropdown, LV_ALIGN_TOP_MID, 0, 50);
  lv_obj_set_style_text_font(dropdown, &lv_font_montserrat_14, 0);

  lv_obj_t *password_ta = lv_textarea_create(wifi_setup_screen);
  lv_textarea_set_password_mode(password_ta, true);
  lv_textarea_set_one_line(password_ta, true);
  lv_textarea_set_placeholder_text(password_ta, "Enter password");
  lv_obj_set_width(password_ta, 300);
  lv_obj_align(password_ta, LV_ALIGN_TOP_MID, 0, 120);
  lv_obj_set_style_text_font(password_ta, &lv_font_montserrat_14, 0);
  lv_obj_add_event_cb(password_ta, keyboard_event_cb, LV_EVENT_FOCUSED, password_ta);
  lv_obj_add_event_cb(password_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, password_ta);

  lv_obj_t *connect_btn = lv_button_create(wifi_setup_screen);
  lv_obj_align(connect_btn, LV_ALIGN_TOP_MID, 0, 190);
  lv_obj_set_size(connect_btn, 120, 40);
  lv_obj_add_event_cb(connect_btn, wifi_connect_cb, LV_EVENT_CLICKED, dropdown);

  lv_obj_t *connect_label = lv_label_create(connect_btn);
  lv_label_set_text(connect_label, "Connect");
  lv_obj_center(connect_label);
  lv_obj_set_style_text_font(connect_label, &lv_font_montserrat_14, 0);

  keyboard = lv_keyboard_create(wifi_setup_screen);
  lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
}

void show_api_code_screen() {
  Serial.println("[APP] Showing API code screen...");
  api_code_screen = lv_obj_create(lv_scr_act());
  lv_obj_set_size(api_code_screen, 800, 480);
  lv_obj_set_style_bg_color(api_code_screen, lv_color_hex(0x000000), 0);

  lv_obj_t *api_ta = lv_textarea_create(api_code_screen);
  lv_textarea_set_one_line(api_ta, true);
  lv_textarea_set_placeholder_text(api_ta, "Enter API code");
  lv_obj_set_width(api_ta, 300);
  lv_obj_align(api_ta, LV_ALIGN_TOP_MID, 0, 100);
  lv_obj_set_style_text_font(api_ta, &lv_font_montserrat_14, 0);
  lv_obj_add_event_cb(api_ta, keyboard_event_cb, LV_EVENT_FOCUSED, api_ta);
  lv_obj_add_event_cb(api_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, api_ta);

  lv_obj_t *submit_btn = lv_button_create(api_code_screen);
  lv_obj_align(submit_btn, LV_ALIGN_TOP_MID, 0, 170);
  lv_obj_set_size(submit_btn, 120, 40);
  lv_obj_add_event_cb(submit_btn, api_code_submit_cb, LV_EVENT_CLICKED, api_ta);

  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);

  keyboard = lv_keyboard_create(api_code_screen);
  lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
}

void new_event_btn_cb(lv_event_t * e) {
  Serial.println("[APP] New event button clicked");
  
  new_event_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(new_event_popup, 600, 400);
  lv_obj_align(new_event_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(new_event_popup, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_color(new_event_popup, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_border_width(new_event_popup, 2, 0);

  NewEventUI *ui = new NewEventUI();

  ui->title_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->title_ta, true);
  lv_textarea_set_placeholder_text(ui->title_ta, "Event title");
  lv_obj_set_width(ui->title_ta, 500);
  lv_obj_align(ui->title_ta, LV_ALIGN_TOP_MID, 0, 20);
  lv_obj_set_style_text_font(ui->title_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(ui->title_ta, lv_color_hex(0x333333), 0); // Darker text
  lv_obj_add_event_cb(ui->title_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->title_ta);
  lv_obj_add_event_cb(ui->title_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->title_ta);

  ui->desc_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->desc_ta, false);
  lv_textarea_set_placeholder_text(ui->desc_ta, "Event description");
  lv_obj_set_size(ui->desc_ta, 500, 80);
  lv_obj_align(ui->desc_ta, LV_ALIGN_TOP_MID, 0, 70);
  lv_obj_set_style_text_font(ui->desc_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(ui->desc_ta, lv_color_hex(0x333333), 0); // Darker text
  lv_obj_add_event_cb(ui->desc_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->desc_ta);
  lv_obj_add_event_cb(ui->desc_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->desc_ta);

  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    Serial.println("[APP] Failed to get local time for preselecting start date");
    timeinfo.tm_year = 2025 - 1900;
    timeinfo.tm_mon = 8;
    timeinfo.tm_mday = 28;
    timeinfo.tm_hour = 11;
    timeinfo.tm_min = 45;
  }
  int current_year = timeinfo.tm_year + 1900;
  int current_month = timeinfo.tm_mon + 1;
  int current_day = timeinfo.tm_mday;
  int current_hour = timeinfo.tm_hour;
  int current_min = (timeinfo.tm_min / 15) * 15;

  time_t start_time = mktime(&timeinfo);
  time_t end_time = start_time + 15 * 60;
  struct tm *end_timeinfo = localtime(&end_time);
  int end_year = end_timeinfo->tm_year + 1900;
  int end_month = end_timeinfo->tm_mon + 1;
  int end_day = end_timeinfo->tm_mday;
  int end_hour = end_timeinfo->tm_hour;
  int end_min = (end_timeinfo->tm_min / 15) * 15;

  lv_obj_t *start_label = lv_label_create(new_event_popup);
  lv_label_set_text(start_label, "Start:");
  lv_obj_align(start_label, LV_ALIGN_TOP_LEFT, 50, 170);
  lv_obj_set_style_text_font(start_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(start_label, lv_color_hex(0xFFFFFF), 0); // Brighter text

  ui->start_year_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->start_year_dd, "2025\n2026\n2027\n2028\n2029\n2030");
  lv_obj_set_width(ui->start_year_dd, 100);
  lv_obj_align(ui->start_year_dd, LV_ALIGN_TOP_LEFT, 100, 170);
  lv_obj_set_style_text_font(ui->start_year_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->start_year_dd, current_year - 2025);

  ui->start_month_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->start_month_dd, "01\n02\n03\n04\n05\n06\n07\n08\n09\n10\n11\n12");
  lv_obj_set_width(ui->start_month_dd, 60);
  lv_obj_align(ui->start_month_dd, LV_ALIGN_TOP_LEFT, 210, 170);
  lv_obj_set_style_text_font(ui->start_month_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->start_month_dd, current_month - 1);

  ui->start_day_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->start_day_dd, "01\n02\n03\n04\n05\n06\n07\n08\n09\n10\n11\n12\n13\n14\n15\n16\n17\n18\n19\n20\n21\n22\n23\n24\n25\n26\n27\n28\n29\n30\n31");
  lv_obj_set_width(ui->start_day_dd, 60);
  lv_obj_align(ui->start_day_dd, LV_ALIGN_TOP_LEFT, 280, 170);
  lv_obj_set_style_text_font(ui->start_day_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->start_day_dd, current_day - 1);

  ui->start_hour_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->start_hour_dd, "00\n01\n02\n03\n04\n05\n06\n07\n08\n09\n10\n11\n12\n13\n14\n15\n16\n17\n18\n19\n20\n21\n22\n23");
  lv_obj_set_width(ui->start_hour_dd, 60);
  lv_obj_align(ui->start_hour_dd, LV_ALIGN_TOP_LEFT, 350, 170);
  lv_obj_set_style_text_font(ui->start_hour_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->start_hour_dd, current_hour);

  ui->start_min_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->start_min_dd, "00\n15\n30\n45");
  lv_obj_set_width(ui->start_min_dd, 60);
  lv_obj_align(ui->start_min_dd, LV_ALIGN_TOP_LEFT, 420, 170);
  lv_obj_set_style_text_font(ui->start_min_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->start_min_dd, current_min / 15);

  lv_obj_t *end_label = lv_label_create(new_event_popup);
  lv_label_set_text(end_label, "End:");
  lv_obj_align(end_label, LV_ALIGN_TOP_LEFT, 50, 220);
  lv_obj_set_style_text_font(end_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(end_label, lv_color_hex(0xFFFFFF), 0); // Brighter text

  ui->end_year_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->end_year_dd, "2025\n2026\n2027\n2028\n2029\n2030");
  lv_obj_set_width(ui->end_year_dd, 100);
  lv_obj_align(ui->end_year_dd, LV_ALIGN_TOP_LEFT, 100, 220);
  lv_obj_set_style_text_font(ui->end_year_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->end_year_dd, end_year - 2025);

  ui->end_month_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->end_month_dd, "01\n02\n03\n04\n05\n06\n07\n08\n09\n10\n11\n12");
  lv_obj_set_width(ui->end_month_dd, 60);
  lv_obj_align(ui->end_month_dd, LV_ALIGN_TOP_LEFT, 210, 220);
  lv_obj_set_style_text_font(ui->end_month_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->end_month_dd, end_month - 1);

  ui->end_day_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->end_day_dd, "01\n02\n03\n04\n05\n06\n07\n08\n09\n10\n11\n12\n13\n14\n15\n16\n17\n18\n19\n20\n21\n22\n23\n24\n25\n26\n27\n28\n29\n30\n31");
  lv_obj_set_width(ui->end_day_dd, 60);
  lv_obj_align(ui->end_day_dd, LV_ALIGN_TOP_LEFT, 280, 220);
  lv_obj_set_style_text_font(ui->end_day_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->end_day_dd, end_day - 1);

  ui->end_hour_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->end_hour_dd, "00\n01\n02\n03\n04\n05\n06\n07\n08\n09\n10\n11\n12\n13\n14\n15\n16\n17\n18\n19\n20\n21\n22\n23");
  lv_obj_set_width(ui->end_hour_dd, 60);
  lv_obj_align(ui->end_hour_dd, LV_ALIGN_TOP_LEFT, 350, 220);
  lv_obj_set_style_text_font(ui->end_hour_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->end_hour_dd, end_hour);

  ui->end_min_dd = lv_dropdown_create(new_event_popup);
  lv_dropdown_set_options(ui->end_min_dd, "00\n15\n30\n45");
  lv_obj_set_width(ui->end_min_dd, 60);
  lv_obj_align(ui->end_min_dd, LV_ALIGN_TOP_LEFT, 420, 220);
  lv_obj_set_style_text_font(ui->end_min_dd, &lv_font_montserrat_14, 0);
  lv_dropdown_set_selected(ui->end_min_dd, end_min / 15);

  lv_obj_t *submit_btn = lv_button_create(new_event_popup);
  lv_obj_align(submit_btn, LV_ALIGN_TOP_MID, -70, 270);
  lv_obj_set_size(submit_btn, 120, 40);
  lv_obj_set_style_bg_color(submit_btn, lv_color_hex(0x00FF00), 0);
  lv_obj_add_event_cb(submit_btn, new_event_submit_cb, LV_EVENT_CLICKED, ui);
  
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);

  lv_obj_t *cancel_btn = lv_button_create(new_event_popup);
  lv_obj_align(cancel_btn, LV_ALIGN_TOP_MID, 70, 270);
  lv_obj_set_size(cancel_btn, 120, 40);
  lv_obj_set_style_bg_color(cancel_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_add_event_cb(cancel_btn, new_event_cancel_cb, LV_EVENT_CLICKED, ui);
  
  lv_obj_t *cancel_label = lv_label_create(cancel_btn);
  lv_label_set_text(cancel_label, "Cancel");
  lv_obj_center(cancel_label);
  lv_obj_set_style_text_font(cancel_label, &lv_font_montserrat_14, 0);

  keyboard = lv_keyboard_create(new_event_popup);
  lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
}

void new_event_submit_cb(lv_event_t * e) {
  Serial.println("[APP] New event submit button clicked");
  
  NewEventUI *ui = (NewEventUI *)lv_event_get_user_data(e);
  if (!ui) {
    Serial.println("[APP] Error: UI structure is null");
    lv_obj_t *error_label = lv_label_create(new_event_popup);
    lv_label_set_text(error_label, "Internal error");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 320);
    return;
  }

  if (!ui->title_ta || !ui->desc_ta || !ui->start_year_dd || !ui->start_month_dd ||
      !ui->start_day_dd || !ui->start_hour_dd || !ui->start_min_dd ||
      !ui->end_year_dd || !ui->end_month_dd || !ui->end_day_dd ||
      !ui->end_hour_dd || !ui->end_min_dd) {
    Serial.println("[APP] Error: One or more UI elements are null");
    lv_obj_t *error_label = lv_label_create(new_event_popup);
    lv_label_set_text(error_label, "Internal error");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 320);
    return;
  }

  String title = String(lv_textarea_get_text(ui->title_ta));
  String description = String(lv_textarea_get_text(ui->desc_ta));

  char buf[10];
  lv_dropdown_get_selected_str(ui->start_year_dd, buf, sizeof(buf));
  String start_year = String(buf);
  lv_dropdown_get_selected_str(ui->start_month_dd, buf, sizeof(buf));
  String start_month = String(buf);
  lv_dropdown_get_selected_str(ui->start_day_dd, buf, sizeof(buf));
  String start_day = String(buf);
  lv_dropdown_get_selected_str(ui->start_hour_dd, buf, sizeof(buf));
  String start_hour = String(buf);
  lv_dropdown_get_selected_str(ui->start_min_dd, buf, sizeof(buf));
  String start_min = String(buf);
  
  lv_dropdown_get_selected_str(ui->end_year_dd, buf, sizeof(buf));
  String end_year = String(buf);
  lv_dropdown_get_selected_str(ui->end_month_dd, buf, sizeof(buf));
  String end_month = String(buf);
  lv_dropdown_get_selected_str(ui->end_day_dd, buf, sizeof(buf));
  String end_day = String(buf);
  lv_dropdown_get_selected_str(ui->end_hour_dd, buf, sizeof(buf));
  String end_hour = String(buf);
  lv_dropdown_get_selected_str(ui->end_min_dd, buf, sizeof(buf));
  String end_min = String(buf);

  String start = start_year + "-" + start_month + "-" + start_day + " " + start_hour + ":" + start_min + ":00";
  String end = end_year + "-" + end_month + "-" + end_day + " " + end_hour + ":" + end_min + ":00";

  struct tm start_tm = {0};
  start_tm.tm_year = start_year.toInt() - 1900;
  start_tm.tm_mon = start_month.toInt() - 1;
  start_tm.tm_mday = start_day.toInt();
  start_tm.tm_hour = start_hour.toInt();
  start_tm.tm_min = start_min.toInt();
  time_t start_time = mktime(&start_tm);

  struct tm end_tm = {0};
  end_tm.tm_year = end_year.toInt() - 1900;
  end_tm.tm_mon = end_month.toInt() - 1;
  end_tm.tm_mday = end_day.toInt();
  end_tm.tm_hour = end_hour.toInt();
  end_tm.tm_min = end_min.toInt();
  time_t end_time = mktime(&end_tm);

  if (start_time == -1 || end_time == -1 || start_time > end_time) {
    Serial.println("[APP] Error: Invalid date/time selection");
    lv_obj_t *error_label = lv_label_create(new_event_popup);
    lv_label_set_text(error_label, "Invalid date/time");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 320);
    return;
  }

  String encoded_title = "";
  String encoded_description = "";
  String encoded_from = "";
  String encoded_to = "";
  for (char c : title) {
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded_title += c;
    } else {
      char hex[4];
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded_title += hex;
    }
  }
  for (char c : description) {
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded_description += c;
    } else {
      char hex[4];
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded_description += hex;
    }
  }
  for (char c : start) {
    if (isalnum(c) || c == '-' || c == ':' || c == '~') {
      encoded_from += c;
    } else {
      char hex[4];
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded_from += hex;
    }
  }
  for (char c : end) {
    if (isalnum(c) || c == '-' || c == ':' || c == '~') {
      encoded_to += c;
    } else {
      char hex[4];
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded_to += hex;
    }
  }

  String url1 = "https://breezbee.co.uk/api/api.php?unique_code=" + apiCode + 
               "&title=" + encoded_title + 
               "&message_description=" + encoded_description + 
               "&from=" + encoded_from + 
               "&to=" + encoded_to;
  Serial.println("[APP] Create URL: " + url1);

  HTTPClient http;
  http.begin(url1);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String response = http.getString();
    Serial.println("[APP] Event submission response: " + response);
    fetchEvents();
    updateEventDisplay(calendar);
  } else {
    String response = http.getString();
    String error_message = "Submission failed: " + response;
    Serial.println("[APP] Event submission failed: HTTP " + String(httpCode) + ", Response: " + response);
    lv_obj_t *error_label = lv_label_create(new_event_popup);
    lv_label_set_text(error_label, error_message.c_str());
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 320);
    http.end();
    return;
  }

  http.end();
  delete ui;
  lv_obj_add_flag(new_event_popup, LV_OBJ_FLAG_HIDDEN);
  lv_obj_del(new_event_popup);
  new_event_popup = nullptr;
}

void new_event_cancel_cb(lv_event_t * e) {
  Serial.println("[APP] New event cancel button clicked");
  NewEventUI *ui = (NewEventUI *)lv_event_get_user_data(e);
  if (ui) {
    delete ui;
  }
  lv_obj_add_flag(new_event_popup, LV_OBJ_FLAG_HIDDEN);
  lv_obj_del(new_event_popup);
  new_event_popup = nullptr;
}

void updateDateTimeLabel() {
  if (!date_time_label) {
    Serial.println("[APP] Error: date_time_label is null in updateDateTimeLabel");
    return;
  }
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    Serial.println("[APP] Failed to get local time in updateDateTimeLabel");
    lv_label_set_text(date_time_label, "Unknown Date/Time");
    return;
  }
  char date_time_str[32];
  strftime(date_time_str, sizeof(date_time_str), "%Y-%m-%d %H:%M:%S", &timeinfo);
  lv_label_set_text(date_time_label, date_time_str);
  Serial.println("[APP] Updated date-time label to: " + String(date_time_str));
}

void updateMonthLabel(lv_obj_t *calendar) {
  if (!calendar) {
    Serial.println("[APP] Error: Calendar object is null in updateMonthLabel");
    if (date_time_label) {
      lv_label_set_text(date_time_label, "Unknown Date/Time");
    }
    return;
  }
  updateDateTimeLabel(); // Update the date-time label instead
}

void checkFirmwareUpdate() {
  Serial.println("[OTA] Starting firmware update check...");
  printMemoryUsage();

  // Check WiFi status
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[OTA] WiFi not connected (status: " + String(WiFi.status()) + "), skipping update check");
    if (update_btn) {
      Serial.println("[OTA] Hiding update button due to no WiFi");
      lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
    }
    // Show version label when WiFi is not connected
    if (!version_label) {
      Serial.println("[OTA] Creating version label");
      version_label = lv_label_create(lv_scr_act());
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_align(version_label, LV_ALIGN_BOTTOM_LEFT, 10, -20); // Same position as update button
      lv_obj_set_style_text_font(version_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(version_label, lv_color_hex(0x555555), 0); // Grey, slightly darker than background
      lv_obj_set_style_text_align(version_label, LV_TEXT_ALIGN_LEFT, 0);
    } else {
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_clear_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    }
    Serial.println("[OTA] Showing version label: Ver " + currentFirmwareVersion);
    return;
  }
  Serial.println("[OTA] WiFi connected, IP: " + WiFi.localIP().toString());

  // Perform HTTP request
  Serial.println("[OTA] Fetching version.json from https://breezbee.co.uk/api/update/version.json");
  client.setInsecure(); // For testing; use CA certificate in production
  HTTPClient http;
  String url = "https://breezbee.co.uk/api/update/version.json";
  http.begin(client, url);
  Serial.println("[OTA] HTTP request initiated");
  int httpCode = http.GET();

  // Check HTTP response
  if (httpCode != HTTP_CODE_OK) {
    Serial.println("[OTA] HTTP request failed with code: " + String(httpCode));
    if (update_btn) {
      Serial.println("[OTA] Hiding update button due to HTTP failure");
      lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
    }
    // Show version label on HTTP failure
    if (!version_label) {
      Serial.println("[OTA] Creating version label");
      version_label = lv_label_create(lv_scr_act());
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_align(version_label, LV_ALIGN_BOTTOM_LEFT, 10, -20);
      lv_obj_set_style_text_font(version_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(version_label, lv_color_hex(0x555555), 0);
      lv_obj_set_style_text_align(version_label, LV_TEXT_ALIGN_LEFT, 0);
    } else {
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_clear_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    }
    Serial.println("[OTA] Showing version label: Ver " + currentFirmwareVersion);
    http.end();
    return;
  }
  Serial.println("[OTA] HTTP request successful (200 OK)");

  // Parse JSON response
  String payload = http.getString();
  Serial.println("[OTA] Response payload: " + payload);
  StaticJsonDocument<256> doc;
  DeserializationError error = deserializeJson(doc, payload);
  if (error) {
    Serial.println("[OTA] JSON parsing failed: " + String(error.c_str()));
    if (update_btn) {
      Serial.println("[OTA] Hiding update button due to JSON parsing error");
      lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
    }
    // Show version label on JSON parsing error
    if (!version_label) {
      Serial.println("[OTA] Creating version label");
      version_label = lv_label_create(lv_scr_act());
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_align(version_label, LV_ALIGN_BOTTOM_LEFT, 10, -20);
      lv_obj_set_style_text_font(version_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(version_label, lv_color_hex(0x555555), 0);
      lv_obj_set_style_text_align(version_label, LV_TEXT_ALIGN_LEFT, 0);
    } else {
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_clear_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    }
    Serial.println("[OTA] Showing version label: Ver " + currentFirmwareVersion);
    http.end();
    return;
  }
  Serial.println("[OTA] JSON parsing successful");

  // Extract version and URL
  latestFirmwareVersion = doc["version"].as<String>();
  firmwareUrl = doc["url"].as<String>();
  Serial.println("[OTA] Current firmware version: " + currentFirmwareVersion);
  Serial.println("[OTA] Latest firmware version: " + latestFirmwareVersion);
  Serial.println("[OTA] Firmware URL: " + firmwareUrl);

  // Check conditions for showing the button or version label
  if (latestFirmwareVersion.isEmpty()) {
    Serial.println("[OTA] latestFirmwareVersion is empty, hiding button");
    if (update_btn) {
      lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
      Serial.println("[OTA] Hiding update button due to empty latestFirmwareVersion");
    }
    // Show version label
    if (!version_label) {
      Serial.println("[OTA] Creating version label");
      version_label = lv_label_create(lv_scr_act());
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_align(version_label, LV_ALIGN_BOTTOM_LEFT, 10, -20);
      lv_obj_set_style_text_font(version_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(version_label, lv_color_hex(0x555555), 0);
      lv_obj_set_style_text_align(version_label, LV_TEXT_ALIGN_LEFT, 0);
    } else {
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_clear_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    }
    Serial.println("[OTA] Showing version label: Ver " + currentFirmwareVersion);
    http.end();
    return;
  }
  if (firmwareUrl.isEmpty()) {
    Serial.println("[OTA] firmwareUrl is empty, hiding button");
    if (update_btn) {
      lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
      Serial.println("[OTA] Hiding update button due to empty firmwareUrl");
    }
    // Show version label
    if (!version_label) {
      Serial.println("[OTA] Creating version label");
      version_label = lv_label_create(lv_scr_act());
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_align(version_label, LV_ALIGN_BOTTOM_LEFT, 10, -20);
      lv_obj_set_style_text_font(version_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(version_label, lv_color_hex(0x555555), 0);
      lv_obj_set_style_text_align(version_label, LV_TEXT_ALIGN_LEFT, 0);
    } else {
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_clear_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    }
    Serial.println("[OTA] Showing version label: Ver " + currentFirmwareVersion);
    http.end();
    return;
  }
  if (latestFirmwareVersion == currentFirmwareVersion) {
    Serial.println("[OTA] No new firmware available (versions match)");
    if (update_btn) {
      lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
      Serial.println("[OTA] Hiding update button due to matching versions");
    }
    // Show version label
    if (!version_label) {
      Serial.println("[OTA] Creating version label");
      version_label = lv_label_create(lv_scr_act());
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_align(version_label, LV_ALIGN_BOTTOM_LEFT, 10, -20);
      lv_obj_set_style_text_font(version_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(version_label, lv_color_hex(0x555555), 0);
      lv_obj_set_style_text_align(version_label, LV_TEXT_ALIGN_LEFT, 0);
    } else {
      lv_label_set_text(version_label, ("Ver " + currentFirmwareVersion).c_str());
      lv_obj_clear_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    }
    Serial.println("[OTA] Showing version label: Ver " + currentFirmwareVersion);
    http.end();
    return;
  }

  // New firmware available, show update button and hide version label
  Serial.println("[OTA] New firmware available!");
  if (version_label) {
    lv_obj_add_flag(version_label, LV_OBJ_FLAG_HIDDEN);
    Serial.println("[OTA] Hiding version label due to new firmware");
  }
  if (!update_btn) {
    Serial.println("[OTA] Creating new update button");
    update_btn = lv_button_create(lv_scr_act());
    lv_obj_add_event_cb(update_btn, update_btn_cb, LV_EVENT_CLICKED, NULL);
    lv_obj_align(update_btn, LV_ALIGN_BOTTOM_LEFT, 10, -20); // Bottom left, aligned with other buttons
    lv_obj_set_size(update_btn, 140, 40);
    lv_obj_set_style_bg_color(update_btn, lv_color_hex(0x00008B), 0); // Dark blue, matching Update Now button
    lv_obj_t *update_label = lv_label_create(update_btn);
    lv_label_set_text(update_label, ("Update ver. " + latestFirmwareVersion).c_str());
    lv_obj_center(update_label);
    lv_obj_set_style_text_font(update_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(update_label, lv_color_hex(0xFFFFFF), 0); // White text for contrast
    Serial.println("[OTA] Update button created at bottom left (x=10, y=-20)");
  } else {
    Serial.println("[OTA] Updating existing update button");
    lv_obj_t *update_label = lv_obj_get_child(update_btn, 0);
    lv_label_set_text(update_label, ("Update ver. " + latestFirmwareVersion).c_str());
    lv_obj_set_style_bg_color(update_btn, lv_color_hex(0x00008B), 0); // Dark blue, matching Update Now button
    lv_obj_align(update_btn, LV_ALIGN_BOTTOM_LEFT, 10, -20); // Bottom left, aligned with other buttons
    lv_obj_clear_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_color(update_label, lv_color_hex(0xFFFFFF), 0); // White text for contrast
    Serial.println("[OTA] Update button made visible at bottom left");
  }
  printMemoryUsage();
  http.end();
}

void updateFirmware() {
  Serial.println("[OTA] Starting firmware update...");
  
  // Clear the screen and set a black background
  lv_obj_clean(lv_scr_act());
  lv_obj_set_style_bg_color(lv_scr_act(), lv_color_hex(0x000000), 0);
  lv_obj_set_style_bg_opa(lv_scr_act(), LV_OPA_100, 0);

  // Create a centered label for the percentage
  if (!update_status_label) {
    update_status_label = lv_label_create(lv_scr_act());
    lv_obj_align(update_status_label, LV_ALIGN_CENTER, 0, 0);
    lv_obj_set_style_text_font(update_status_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(update_status_label, lv_color_hex(0xFFFFFF), 0); // White text
    lv_obj_set_style_text_align(update_status_label, LV_TEXT_ALIGN_CENTER, 0);
  }
  lv_label_set_text(update_status_label, "0%");
  lv_obj_clear_flag(update_status_label, LV_OBJ_FLAG_HIDDEN);
  loop_display(); // Initial display refresh
  printMemoryUsage();

  // Simulate percentage counting for 3 seconds
  const int countdown_duration = 3000; // 3 seconds
  const int steps = 10; // Increment by 10% each step
  const int delay_per_step = countdown_duration / steps; // 300ms per step
  for (int i = 1; i <= steps; i++) {
    int percentage = i * 10; // 10%, 20%, ..., 100%
    Serial.println("[OTA] Countdown progress: " + String(percentage) + "%");
    lv_label_set_text(update_status_label, (String(percentage) + "%").c_str());
    loop_display(); // Refresh display
    delay(delay_per_step); // Wait for 300ms
  }

  // Proceed with actual firmware update
  Serial.println("[OTA] Starting actual firmware download...");
  client.setInsecure(); // For testing; use CA certificate in production
  HTTPClient http;
  http.begin(client, firmwareUrl);

  int httpCode = http.GET();
  if (httpCode != HTTP_CODE_OK) {
    Serial.println("[OTA] Failed to download firmware: HTTP " + String(httpCode));
    lv_label_set_text(update_status_label, "Update failed: HTTP error");
    loop_display();
    http.end();
    return;
  }

  int contentLength = http.getSize();
  Serial.println("[OTA] Firmware size: " + String(contentLength) + " bytes");
  if (contentLength <= 0) {
    Serial.println("[OTA] Invalid content length");
    lv_label_set_text(update_status_label, "Update failed: Invalid size");
    loop_display();
    http.end();
    return;
  }

  if (!Update.begin(contentLength)) {
    Serial.println("[OTA] Not enough space for update");
    lv_label_set_text(update_status_label, "Update failed: Not enough space");
    loop_display();
    http.end();
    return;
  }

  WiFiClient *stream = http.getStreamPtr();
  size_t written = 0;
  uint8_t buff[512];
  int lastProgress = -1; // Track last displayed progress to reduce updates
  while (http.connected() && written < contentLength) {
    size_t size = stream->available();
    if (size) {
      int c = stream->readBytes(buff, min(sizeof(buff), size));
      if (Update.write(buff, c) != c) {
        Serial.println("[OTA] Error writing to flash");
        lv_label_set_text(update_status_label, "Update failed: Write error");
        loop_display();
        http.end();
        return;
      }
      written += c;
      int progress = (written * 100) / contentLength;
      if (progress != lastProgress) { // Update display only when progress changes
        Serial.println("[OTA] Download progress: " + String(progress) + "%");
        lv_label_set_text(update_status_label, (String(progress) + "%").c_str());
        loop_display(); // Refresh display
        lastProgress = progress;
      }
      delay(1);
    }
  }

  if (written != contentLength) {
    Serial.println("[OTA] Incomplete download");
    lv_label_set_text(update_status_label, "Update failed: Incomplete");
    loop_display();
    http.end();
    return;
  }

  if (!Update.end(true)) {
    Serial.println("[OTA] Error finalizing update");
    lv_label_set_text(update_status_label, "Update failed: Finalization error");
    loop_display();
    http.end();
    return;
  }

  // Save new firmware version
  preferences.begin("firmware", false);
  preferences.putString("version", latestFirmwareVersion);
  preferences.end();
  Serial.println("[OTA] Firmware version saved: " + latestFirmwareVersion);

  Serial.println("[OTA] Update successful, rebooting...");
  lv_label_set_text(update_status_label, "100%");
  loop_display();
  delay(1000);
  ESP.restart();
  http.end();
}

// New callback for the "Update Now" button
void update_now_btn_cb(lv_event_t *e) {
  Serial.println("[OTA] Update Now button clicked");
  if (update_popup) {
    lv_obj_add_flag(update_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(update_popup);
    update_popup = nullptr;
    Serial.println("[OTA] Update popup hidden and deleted");
  }
  if (update_btn) {
    lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN);
    Serial.println("[OTA] Update button hidden before update");
  }
  updateFirmware();
}

// Modified update_btn_cb function
void update_btn_cb(lv_event_t *e) {
  Serial.println("[OTA] Update button clicked");
  if (update_btn) {
    lv_obj_add_flag(update_btn, LV_OBJ_FLAG_HIDDEN); // Hide update button
    Serial.println("[OTA] Update button hidden before popup");
  }

  // Create full-screen black popup with square corners
  update_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(update_popup, 800, 480); // Full screen
  lv_obj_align(update_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(update_popup, lv_color_hex(0x000000), 0); // Black background
  lv_obj_set_style_border_width(update_popup, 0, 0); // No border
  lv_obj_set_style_radius(update_popup, 0, 0); // Square corners (no rounded corners)

  // Create "Update Now" button
  lv_obj_t *update_now_btn = lv_button_create(update_popup);
  lv_obj_align(update_now_btn, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_size(update_now_btn, 140, 40);
  lv_obj_set_style_bg_color(update_now_btn, lv_color_hex(0x00008B), 0); // Dark blue, darker than default button
  lv_obj_add_event_cb(update_now_btn, [](lv_event_t *e) {
    Serial.println("[OTA] Update Now button clicked");
    if (update_popup) {
      lv_obj_add_flag(update_popup, LV_OBJ_FLAG_HIDDEN); // Hide popup
      lv_obj_del(update_popup); // Delete popup to free memory
      update_popup = nullptr;
      Serial.println("[OTA] Update popup removed");
    }
    updateFirmware(); // Proceed with firmware update
  }, LV_EVENT_CLICKED, NULL);

  lv_obj_t *update_now_label = lv_label_create(update_now_btn);
  lv_label_set_text(update_now_label, "Update Now");
  lv_obj_center(update_now_label);
  lv_obj_set_style_text_font(update_now_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(update_now_label, lv_color_hex(0xFFFFFF), 0); // White text for contrast

  Serial.println("[OTA] Update popup created with Update Now button");
}

void setup_calendar() {
  Serial.println("[APP] Setting up calendar...");
  Serial.println("[APP] Initializing NTP...");
  configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
  Serial.println("[APP] Waiting for NTP time sync...");
  struct tm timeinfo;
  int ntp_attempts = 0;
  while (!getLocalTime(&timeinfo) && ntp_attempts < 5) {
    Serial.println("[APP] Retrying NTP sync...");
    delay(1000);
    ntp_attempts++;
  }
  if (!getLocalTime(&timeinfo)) {
    Serial.println("[APP] NTP sync failed");
  } else {
    Serial.println("[APP] NTP synced: " + String(asctime(&timeinfo)));
  }
  printMemoryUsage();

  Serial.println("[APP] Starting display setup...");
  setup_display();
  Serial.println("[APP] Display setup complete");

  Serial.println("[APP] Creating calendar...");
  calendar = lv_calendar_create(lv_scr_act());
  if (!calendar) {
    Serial.println("[APP] Error: Failed to create calendar");
    return;
  }
  lv_obj_set_size(calendar, 350, 350);
  lv_obj_align(calendar, LV_ALIGN_TOP_LEFT, 10, 50);
  lv_calendar_set_showed_date(calendar, 2025, 9);
  lv_obj_add_event_cb(calendar, calendar_event_cb, LV_EVENT_VALUE_CHANGED, NULL);

  static lv_style_t style_highlight;
  lv_style_init(&style_highlight);
  lv_style_set_bg_color(&style_highlight, lv_color_hex(0xFF0000));
  lv_obj_add_style(calendar, &style_highlight, LV_PART_ITEMS | LV_STATE_CHECKED);

  Serial.println("[APP] Creating date-time label...");
  date_time_label = lv_label_create(lv_scr_act());
  lv_obj_set_style_text_font(date_time_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(date_time_label, lv_color_hex(0x000000), 0);
  lv_obj_align(date_time_label, LV_ALIGN_TOP_MID, 0, 10);
  updateDateTimeLabel();

  Serial.println("[APP] Fetching initial events...");
  fetchEvents();
  updateEventDisplay(calendar);

  Serial.println("[APP] Creating new event button...");
  lv_obj_t *new_event_btn = lv_button_create(lv_scr_act());
  lv_obj_add_event_cb(new_event_btn, new_event_btn_cb, LV_EVENT_CLICKED, nullptr);
  lv_obj_align(new_event_btn, LV_ALIGN_BOTTOM_MID, -130, -20);
  lv_obj_set_size(new_event_btn, 120, 40);
  lv_obj_set_style_bg_color(new_event_btn, lv_color_hex(0x008000), 0);

  lv_obj_t *new_event_label = lv_label_create(new_event_btn);
  lv_label_set_text(new_event_label, "+ New Event");
  lv_obj_center(new_event_label);
  lv_obj_set_style_text_font(new_event_label, &lv_font_montserrat_14, 0);

  Serial.println("[APP] Creating refresh button...");
  lv_obj_t *btn1 = lv_button_create(lv_scr_act());
  lv_obj_add_event_cb(btn1, button_event_cb, LV_EVENT_CLICKED, calendar);
  lv_obj_align(btn1, LV_ALIGN_BOTTOM_MID, 0, -20);
  lv_obj_set_size(btn1, 120, 40);

  lv_obj_t *btn1label = lv_label_create(btn1);
  lv_label_set_text(btn1label, "Refresh");
  lv_obj_center(btn1label);

  Serial.println("[APP] Creating WiFi logout button...");
  lv_obj_t *wifi_logout_btn = lv_button_create(lv_scr_act());
  lv_obj_add_event_cb(wifi_logout_btn, wifi_logout_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_align(wifi_logout_btn, LV_ALIGN_BOTTOM_RIGHT, -10, -20);
  lv_obj_set_size(wifi_logout_btn, 120, 40);
  lv_obj_set_style_bg_color(wifi_logout_btn, lv_color_hex(0x333333), 0); // Match update button color

  lv_obj_t *wifi_logout_label = lv_label_create(wifi_logout_btn);
  lv_label_set_text(wifi_logout_label, "WiFi Logout");
  lv_obj_center(wifi_logout_label);
  lv_obj_set_style_text_font(wifi_logout_label, &lv_font_montserrat_14, 0);

  Serial.println("[APP] Creating API logout button...");
  lv_obj_t *api_logout_btn = lv_button_create(lv_scr_act());
  lv_obj_add_event_cb(api_logout_btn, api_logout_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_align(api_logout_btn, LV_ALIGN_BOTTOM_RIGHT, -150, -20);
  lv_obj_set_size(api_logout_btn, 120, 40);
  lv_obj_set_style_bg_color(api_logout_btn, lv_color_hex(0x333333), 0); // Match update button color

  lv_obj_t *api_logout_label = lv_label_create(api_logout_btn);
  lv_label_set_text(api_logout_label, "API Logout");
  lv_obj_center(api_logout_label);
  lv_obj_set_style_text_font(api_logout_label, &lv_font_montserrat_14, 0);

  Serial.println("[APP] Checking for firmware updates...");
  checkFirmwareUpdate();

  Serial.println("[APP] Setup complete");
  printMemoryUsage();
}

void button_event_cb(lv_event_t * e) {
  Serial.println("[APP] Refresh button clicked");
  lv_obj_t * btn = (lv_obj_t *)lv_event_get_target(e);
  lv_obj_t * label = lv_obj_get_child(btn, 0);
  static bool toggle = false;
  toggle = !toggle;
  lv_label_set_text(label, toggle ? "Refresh!" : "Refresh");
  fetchEvents();
  updateEventDisplay((lv_obj_t *)lv_event_get_user_data(e));
  updateMonthLabel((lv_obj_t *)lv_event_get_user_data(e));
}

void calendar_event_cb(lv_event_t * e) {
  unsigned long currentTime = millis();
  if (currentTime - lastEventTime < debounceDelay) {
    Serial.println("[APP] Event debounced");
    return;
  }
  lastEventTime = currentTime;

  Serial.println("[APP] Calendar date selected");
  if (!e) {
    Serial.println("[APP] Error: Event object is null");
    return;
  }

  lv_obj_t *target = (lv_obj_t *)lv_event_get_target(e);
  if (!target) {
    Serial.println("[APP] Error: Event target is null");
    return;
  }

  if (target != calendar) {
    Serial.println("[APP] Error: Event target is not the calendar object");
    return;
  }

  Serial.println("[APP] Retrieving pressed date...");
  lv_calendar_date_t date;
  if (!lv_calendar_get_pressed_date(target, &date)) {
    Serial.println("[APP] Failed to get pressed date");
    return;
  }

  char date_str[32];
  snprintf(date_str, sizeof(date_str), "%04d-%02d-%02d", date.year, date.month, date.day);
  Serial.println("[APP] Selected date: " + String(date_str));

  if (date.year < 1970 || date.year > 2030 || date.month < 1 || date.month > 12 || date.day < 1 || date.day > 31) {
    Serial.println("[APP] Invalid date selected: " + String(date_str));
    return;
  }

  if (!isDateHighlightable(date.year, date.month, date.day)) {
    Serial.println("[APP] Selected date is not highlightable, ignoring");
    if (eventContainer) {
      lv_obj_clean(eventContainer);
      lv_obj_t *noEventsLabel = lv_label_create(eventContainer);
      lv_label_set_text(noEventsLabel, "No events on this date");
      lv_obj_set_style_text_font(noEventsLabel, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(noEventsLabel, lv_color_hex(0xFFFFFF), 0);
      lv_obj_align(noEventsLabel, LV_ALIGN_TOP_LEFT, 10, 10);
    } else {
      Serial.println("[APP] Error: eventContainer is null");
    }
    return;
  }

  updateMonthLabel(target);

  if (eventContainer) {
    lv_obj_clean(eventContainer);
  } else {
    Serial.println("[APP] Error: eventContainer is null, recreating");
    eventContainer = lv_obj_create(lv_scr_act());
    lv_obj_set_size(eventContainer, 400, 350);
    lv_obj_align(eventContainer, LV_ALIGN_TOP_RIGHT, -10, 50);
    lv_obj_set_style_bg_color(eventContainer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_width(eventContainer, 0, 0);
    lv_obj_set_scrollbar_mode(eventContainer, LV_SCROLLBAR_MODE_AUTO);
  }

  int y_offset = 10;
  int event_count = 0;

  Serial.println("[APP] Checking events for date: " + String(date_str));
  for (int i = 0; i < numEvents; i++) {
    String startDate = events[i].start;
    String endDate = events[i].end;
    if (startDate.length() > 10) startDate = startDate.substring(0, 10);
    if (endDate.length() > 10) endDate = endDate.substring(0, 10);
    int startYear, startMonth, startDay;
    int endYear, endMonth, endDay;
    if (sscanf(startDate.c_str(), "%d-%d-%d", &startYear, &startMonth, &startDay) != 3 ||
        sscanf(endDate.c_str(), "%d-%d-%d", &endYear, &endMonth, &endDay) != 3) {
      Serial.println("[APP] Failed to parse event " + String(i) + " dates: " + startDate + " to " + endDate);
      continue;
    }
    struct tm start_tm = {0};
    start_tm.tm_year = startYear - 1900;
    start_tm.tm_mon = startMonth - 1;
    start_tm.tm_mday = startDay;
    struct tm end_tm = {0};
    end_tm.tm_year = endYear - 1900;
    end_tm.tm_mon = endMonth - 1;
    end_tm.tm_mday = endDay;
    time_t start_time = mktime(&start_tm);
    time_t end_time = mktime(&end_tm);
    if (start_time == -1 || end_time == -1) {
      Serial.println("[APP] Invalid time conversion for event " + String(i));
      continue;
    }

    struct tm selected_tm = {0};
    selected_tm.tm_year = date.year - 1900;
    selected_tm.tm_mon = date.month - 1;
    selected_tm.tm_mday = date.day;
    time_t selected_time = mktime(&selected_tm);
    if (selected_time == -1) {
      Serial.println("[APP] Invalid selected date conversion: " + String(date_str));
      continue;
    }

    if (selected_time >= start_time && selected_time <= end_time) {
      lv_obj_t *eventLabel = lv_label_create(eventContainer);
      String summary = events[i].summary;
      if (summary.length() > 55) summary = summary.substring(0, 55); // Limit title to 55 characters
      String description = events[i].description;
      if (description.length() > 55) description = description.substring(0, 55); // Limit description to 55 characters
      String timeText = events[i].isAllDay ? "All day" : 
                        "from " + events[i].start.substring(11, 16) + " to " + events[i].end.substring(11, 16);
      String text = summary;
      if (startDate == endDate) {
        text += " (" + timeText + ")";
      } else {
        text += " (" + startDate + " to " + endDate + ")";
      }
      if (description.length() > 0) {
        text += "\n" + description;
      }
      lv_label_set_text(eventLabel, text.c_str());
      lv_obj_set_style_text_font(eventLabel, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(eventLabel, lv_color_hex(0xFFFFFF), 0);
      lv_obj_set_style_text_align(eventLabel, LV_TEXT_ALIGN_LEFT, 0);
      lv_obj_align(eventLabel, LV_ALIGN_TOP_LEFT, 10, y_offset);
      y_offset += 50;
      event_count++;
      Serial.println("[APP] Matched event " + String(i) + ": " + summary);
    }
  }

  if (event_count == 0) {
    lv_obj_t *noEventsLabel = lv_label_create(eventContainer);
    lv_label_set_text(noEventsLabel, "No events on this date");
    lv_obj_set_style_text_font(noEventsLabel, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(noEventsLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_align(noEventsLabel, LV_ALIGN_TOP_LEFT, 10, y_offset);
    Serial.println("[APP] No events matched for selected date");
  }
  Serial.println("[APP] Calendar event display updated with " + String(event_count) + " events");
}
void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.printf("[APP] Free heap at start: %d bytes, Free PSRAM: %d bytes\n",
                heap_caps_get_free_size(MALLOC_CAP_8BIT),
                heap_caps_get_free_size(MALLOC_CAP_SPIRAM));

  // Load firmware version from Preferences
  preferences.begin("firmware", false);
  currentFirmwareVersion = preferences.getString("version", "1.0.0");
  preferences.end();
  Serial.println("[APP] Loaded firmware version: " + currentFirmwareVersion);

  // Test Preferences read/write
  preferences.begin("firmware", false);
  String testWrite = "test_version";
  preferences.putString("test_key", testWrite);
  String testRead = preferences.getString("test_key", "");
  if (testRead == testWrite) {
    Serial.println("[APP] Preferences read/write test passed");
  } else {
    Serial.println("[APP] Preferences read/write test failed: wrote " + testWrite + ", read " + testRead);
  }
  preferences.end();

  preferences.begin("wifi", false);
  ssid = preferences.getString("ssid", "");
  password = preferences.getString("password", "");
  preferences.end();

  if (ssid == "" || password == "") {
    Serial.println("[APP] No WiFi credentials found, showing WiFi setup screen");
    setup_display();
    show_wifi_setup_screen();
  } else {
    Serial.println("[APP] Connecting to WiFi: " + ssid);
    WiFi.begin(ssid.c_str(), password.c_str());
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
      delay(500);
      Serial.print(".");
      attempts++;
    }
    if (WiFi.status() == WL_CONNECTED) {
      Serial.println("[APP] WiFi connected! IP: " + WiFi.localIP().toString());
      preferences.begin("api", false);
      apiCode = preferences.getString("apiCode", "");
      preferences.end();
      if (apiCode == "") {
        Serial.println("[APP] No API code found, showing API code screen");
        setup_display();
        show_api_code_screen();
      } else {
        Serial.println("[APP] API code found, setting up calendar");
        setup_calendar();
      }
    } else {
      Serial.println("[APP] Stored WiFi credentials failed (status: " + String(WiFi.status()) + ")");
      setup_display();
      show_wifi_setup_screen();
    }
  }
}

void loop() {
  loop_display();
  delay(5);

  unsigned long currentTime = millis();
  if (currentTime - lastRefreshTime >= refreshInterval && calendar && WiFi.status() == WL_CONNECTED) {
    Serial.println("[APP] Auto-refresh triggered");
    fetchEvents();
    updateEventDisplay(calendar);
    updateMonthLabel(calendar);
    checkFirmwareUpdate();
    lastRefreshTime = currentTime;
  }
}