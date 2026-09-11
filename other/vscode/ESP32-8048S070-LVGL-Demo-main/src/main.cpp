#include "display.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <time.h>
#include <Preferences.h>
#include <Update.h>
#include <WiFiClientSecure.h>
// Firmware check interval variable
const unsigned long firmwareCheckInterval = 100000UL; // 5 minutes in milliseconds
// Forward declarations
void fetchEvents();
void updateEventDisplay(lv_obj_t *calendar);
void fetchWeather();
void updateWeatherDisplay();
void wifi_connect_cb(lv_event_t *e);
void api_code_submit_cb(lv_event_t *e);
void location_submit_cb(lv_event_t *e);
void wifi_logout_cb(lv_event_t *e);
void api_logout_cb(lv_event_t *e);
void keyboard_event_cb(lv_event_t *e);
void show_wifi_setup_screen();
void show_api_code_screen();
void show_location_screen();
void show_settings_popup();
void setup_calendar();
void button_event_cb(lv_event_t *e);
void calendar_event_cb(lv_event_t *e);
void new_event_btn_cb(lv_event_t *e);
void settings_btn_cb(lv_event_t *e);
void show_new_event_popup(lv_calendar_date_t *selected_date = nullptr);
void new_event_submit_cb(lv_event_t *e);
void new_event_cancel_cb(lv_event_t *e);
void checkFirmwareUpdate();
void updateFirmware();
void update_btn_cb(lv_event_t *e);
void printMemoryUsage();
void updateDateTimeLabel(); // New function to update date-time label
void show_event_details(int index);
void show_event_details_cb(lv_event_t *e);
void close_event_details_cb(lv_event_t *e);
void cancel_reminder_cb(lv_event_t *e);
void prev_month_cb(lv_event_t *e);
void next_month_cb(lv_event_t *e);
void updateFirmwareButton();
// Preferences for WiFi, API code, and firmware version
Preferences preferences;
// LVGL objects
static lv_obj_t *wifi_setup_screen = nullptr;
static lv_obj_t *api_code_screen = nullptr;
static lv_obj_t *location_screen = nullptr;
static lv_obj_t *settings_popup = nullptr;
static lv_obj_t *new_event_popup = nullptr;
static lv_obj_t *keyboard = nullptr;
static lv_obj_t *date_time_label = nullptr; // Renamed from month_label
static lv_obj_t *month_label = nullptr;
static lv_obj_t *eventContainer = nullptr;
static lv_obj_t *weatherContainer = nullptr;
static lv_obj_t *calendar = nullptr;
static lv_obj_t *update_btn = nullptr;
static lv_obj_t *version_label = nullptr;
static lv_obj_t *update_popup = nullptr;
static lv_obj_t *update_status_label = nullptr;
static lv_obj_t *event_details_popup = nullptr;
static lv_obj_t *firmware_update_btn = nullptr;
static lv_obj_t *button_bar = nullptr;
// Structure to hold new event UI elements
struct NewEventUI {
  lv_obj_t *title_ta;
  lv_obj_t *desc_ta;
  lv_obj_t *start_year_ta;
  lv_obj_t *start_month_ta;
  lv_obj_t *start_day_ta;
  lv_obj_t *start_hour_ta;
  lv_obj_t *start_min_ta;
  lv_obj_t *end_year_ta;
  lv_obj_t *end_month_ta;
  lv_obj_t *end_day_ta;
  lv_obj_t *end_hour_ta;
  lv_obj_t *end_min_ta;
  lv_obj_t *remind_before_ta;
};
// Event structure
struct Event {
  String summary;
  String start;
  String end;
  String description;
  bool isToday;
  bool isAllDay;
  time_t start_time;
  time_t end_time;
  bool notified;
  int remind_before;
  int reminder_count;
  time_t last_reminder_time;
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
String location;
String lat;
String lon;
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
const unsigned long dateTimeUpdateInterval = 60000; // Update every 1 minute (changed from 1000)
// Timer for updating weather
static unsigned long lastWeatherUpdate = 0;
const unsigned long weatherUpdateInterval = 3600000; // Update every hour
// Timer for firmware check
static unsigned long lastFirmwareCheck = 0;
// Weather data
struct CurrentWeather {
  float temperature_2m;
  float relative_humidity_2m;
  float apparent_temperature;
  float precipitation;
  int weather_code;
  float wind_speed_10m;
  int wind_direction_10m;
};
CurrentWeather current_weather;
// Helper function to print memory usage
void printMemoryUsage() {
  Serial.printf("[DEBUG] Free heap: %d bytes, Free PSRAM: %d bytes\n",
                heap_caps_get_free_size(MALLOC_CAP_8BIT),
                heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
}
String getWeatherDescription(int code) {
  switch (code) {
    case 0: return "Clear sky";
    case 1: return "Mainly clear";
    case 2: return "Partly cloudy";
    case 3: return "Overcast";
    case 45: return "Fog";
    case 48: return "Depositing rime fog";
    case 51: return "Light drizzle";
    case 53: return "Moderate drizzle";
    case 55: return "Dense drizzle";
    case 61: return "Slight rain";
    case 63: return "Moderate rain";
    case 65: return "Heavy rain";
    case 71: return "Slight snow fall";
    case 73: return "Moderate snow fall";
    case 75: return "Heavy snow fall";
    case 80: return "Slight rain showers";
    case 81: return "Moderate rain showers";
    case 82: return "Violent rain showers";
    case 95: return "Thunderstorm";
    case 96: return "Thunderstorm with slight hail";
    case 99: return "Thunderstorm with heavy hail";
    default: return "Unknown";
  }
}
const char* getWeatherIcon(int code) {
  if (code >= 0 && code <= 2) return LV_SYMBOL_OK; // Sunny
  if (code == 3) return LV_SYMBOL_WARNING; // Cloudy
  if (code >= 45 && code <= 48) return LV_SYMBOL_WARNING; // Fog
  if ((code >= 51 && code <= 55) || (code >= 61 && code <= 65) || (code >= 80 && code <= 82)) return LV_SYMBOL_WARNING; // Rain
  if (code >= 71 && code <= 75) return LV_SYMBOL_WARNING; // Snow
  if (code >= 95) return LV_SYMBOL_WARNING; // Thunderstorm
  return LV_SYMBOL_OK; // Default
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
    JsonDocument doc;
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
      String remind_before_str = eventObj["remind_before"].as<String>();
      if (events[numEvents].start.isEmpty() || events[numEvents].end.isEmpty()) {
        Serial.println("[APP] Event " + String(numEvents) + " has empty start/end, skipping");
        continue;
      }
      events[numEvents].isAllDay = (events[numEvents].start.endsWith("00:00:00") &&
                                   (events[numEvents].end.endsWith("23:59:00") ||
                                    events[numEvents].end.endsWith("23:59:59")));
      Serial.println("[APP] Event " + String(numEvents) + ":");
      Serial.println(" Summary: " + events[numEvents].summary);
      Serial.println(" Start: " + events[numEvents].start);
      Serial.println(" End: " + events[numEvents].end);
      Serial.println(" Description: " + events[numEvents].description);
      Serial.println(" Remind before: " + remind_before_str);
      Serial.println(" AllDay: " + String(events[numEvents].isAllDay ? "Yes" : "No"));
      // Parse remind_before
      int remind_before_minutes = 0;
      if (!remind_before_str.isEmpty()) {
        char unit = remind_before_str.charAt(remind_before_str.length() - 1);
        String value_str = remind_before_str.substring(0, remind_before_str.length() - 1);
        int value = value_str.toInt();
        if (unit == 'm') {
          remind_before_minutes = value;
        } else if (unit == 'h') {
          remind_before_minutes = value * 60;
        } else if (unit == 'd') {
          remind_before_minutes = value * 1440;
        } else {
          remind_before_minutes = remind_before_str.toInt(); // fallback
        }
      }
      events[numEvents].remind_before = remind_before_minutes;
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
      int startHour = 0, startMin = 0, startSec = 0;
      int endHour = 0, endMin = 0, endSec = 0;
      if (!events[numEvents].isAllDay) {
        sscanf(events[numEvents].start.c_str() + 11, "%d:%d:%d", &startHour, &startMin, &startSec);
        sscanf(events[numEvents].end.c_str() + 11, "%d:%d:%d", &endHour, &endMin, &endSec);
      } else if (events[numEvents].isAllDay) {
        endHour = 23;
        endMin = 59;
        endSec = 59;
      }
      struct tm start_tm = {0};
      start_tm.tm_year = startYear - 1900;
      start_tm.tm_mon = startMonth - 1;
      start_tm.tm_mday = startDay;
      start_tm.tm_hour = startHour;
      start_tm.tm_min = startMin;
      start_tm.tm_sec = startSec;
      struct tm end_tm = {0};
      end_tm.tm_year = endYear - 1900;
      end_tm.tm_mon = endMonth - 1;
      end_tm.tm_mday = endDay;
      end_tm.tm_hour = endHour;
      end_tm.tm_min = endMin;
      end_tm.tm_sec = endSec;
      time_t start_time = mktime(&start_tm);
      time_t end_time = mktime(&end_tm);
      if (start_time == -1 || end_time == -1) {
        Serial.println("[APP] Invalid time conversion for event " + String(numEvents));
        continue;
      }
      events[numEvents].start_time = start_time;
      events[numEvents].end_time = end_time;
      events[numEvents].notified = false;
      events[numEvents].reminder_count = 0;
      events[numEvents].last_reminder_time = 0;
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
void fetchWeather() {
  Serial.println("[APP] Fetching weather...");
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[APP] WiFi not connected");
    return;
  }
  // First, geocode if lat/lon not set
  if (lat.isEmpty() || lon.isEmpty()) {
    HTTPClient httpGeo;
    String geoUrl = "https://geocoding-api.open-meteo.com/v1/search?name=" + location + "&count=1&language=en&format=json";
    httpGeo.begin(geoUrl);
    int httpCodeGeo = httpGeo.GET();
    if (httpCodeGeo == HTTP_CODE_OK) {
      String payloadGeo = httpGeo.getString();
      JsonDocument docGeo;
      DeserializationError error = deserializeJson(docGeo, payloadGeo);
      if (!error) {
        JsonObject result = docGeo["results"][0];
        lat = String(result["latitude"].as<float>(), 6);
        lon = String(result["longitude"].as<float>(), 6);
        preferences.begin("location", false);
        preferences.putString("lat", lat);
        preferences.putString("lon", lon);
        preferences.end();
        Serial.println("[APP] Geocoded location to lat: " + lat + ", lon: " + lon);
      }
    }
    httpGeo.end();
  }
  if (lat.isEmpty() || lon.isEmpty()) {
    Serial.println("[APP] Failed to geocode location");
    return;
  }
  // Get current weather from Open-Meteo
  HTTPClient http;
  String url = "https://api.open-meteo.com/v1/forecast?latitude=" + lat + "&longitude=" + lon + "&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_direction_10m&timezone=auto&forecast_days=1";
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      Serial.println("[APP] Weather JSON parsing failed: " + String(error.c_str()));
      return;
    }
    JsonObject current = doc["current"];
    current_weather.temperature_2m = current["temperature_2m"].as<float>();
    current_weather.relative_humidity_2m = current["relative_humidity_2m"].as<float>();
    current_weather.apparent_temperature = current["apparent_temperature"].as<float>();
    current_weather.precipitation = current["precipitation"].as<float>();
    current_weather.weather_code = current["weather_code"].as<int>();
    current_weather.wind_speed_10m = current["wind_speed_10m"].as<float>();
    current_weather.wind_direction_10m = current["wind_direction_10m"].as<int>();
    Serial.println("[APP] Fetched current weather");
  } else {
    Serial.println("[APP] Weather request failed: " + String(httpCode));
  }
  http.end();
}
void updateWeatherDisplay() {
Serial.println("[APP] Updating weather display...");
if (!weatherContainer) {
weatherContainer = lv_obj_create(lv_scr_act());
lv_obj_set_size(weatherContainer, 400, 225);
lv_obj_align(weatherContainer, LV_ALIGN_BOTTOM_RIGHT, -10, -13);
lv_obj_set_style_bg_color(weatherContainer, lv_color_hex(0xFFFFFF), 0);
lv_obj_set_style_bg_opa(weatherContainer, LV_OPA_90, 0);
lv_obj_set_style_border_width(weatherContainer, 0, 0);
lv_obj_set_style_radius(weatherContainer, 20, 0);
lv_obj_set_style_shadow_color(weatherContainer, lv_color_hex(0x000000), 0);
lv_obj_set_style_shadow_width(weatherContainer, 40, 0);
lv_obj_set_style_shadow_opa(weatherContainer, LV_OPA_10, 0);
Serial.println("[APP] Created weatherContainer");
} else {
lv_obj_clean(weatherContainer);
}
lv_obj_set_scrollbar_mode(weatherContainer, LV_SCROLLBAR_MODE_OFF);
// Location
lv_obj_t *loc_label = lv_label_create(weatherContainer);
lv_label_set_text(loc_label, location.c_str());
lv_obj_set_style_text_color(loc_label, lv_color_hex(0x636e72), 0);
lv_obj_set_style_text_font(loc_label, &lv_font_montserrat_14, 0);
lv_obj_align(loc_label, LV_ALIGN_TOP_LEFT, 10, 10);
// Temperature
lv_obj_t *temp_label = lv_label_create(weatherContainer);
lv_label_set_text(temp_label, (String(round(current_weather.temperature_2m)) + "°C").c_str());
lv_obj_set_style_text_font(temp_label, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(temp_label, lv_color_hex(0x2d3436), 0);
lv_obj_align_to(temp_label, loc_label, LV_ALIGN_OUT_RIGHT_MID, 10, 0);
// Description
String desc = getWeatherDescription(current_weather.weather_code);
lv_obj_t *desc_label = lv_label_create(weatherContainer);
lv_label_set_text(desc_label, desc.c_str());
lv_obj_set_style_text_font(desc_label, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(desc_label, lv_color_hex(0x636e72), 0);
lv_obj_align(desc_label, LV_ALIGN_TOP_MID, 0, 31);
// Feels like
lv_obj_t *feels_cont = lv_obj_create(weatherContainer);
lv_obj_set_size(feels_cont, 184, 48);
lv_obj_align(feels_cont, LV_ALIGN_TOP_MID, -100, 90);
lv_obj_set_style_bg_color(feels_cont, lv_color_hex(0xa29bfe), 0);
lv_obj_set_style_bg_grad_color(feels_cont, lv_color_hex(0x6c5ce7), 0);
lv_obj_set_style_bg_grad_dir(feels_cont, LV_GRAD_DIR_HOR, 0);
lv_obj_set_style_radius(feels_cont, 10, 0);
lv_obj_t *feels_val = lv_label_create(feels_cont);
lv_label_set_text(feels_val, ("Feels\n" + String(round(current_weather.apparent_temperature)) + "°C").c_str());
lv_obj_set_style_text_font(feels_val, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(feels_val, lv_color_hex(0xFFFFFF), 0);
lv_obj_center(feels_val);
// Humidity
lv_obj_t *hum_cont = lv_obj_create(weatherContainer);
lv_obj_set_size(hum_cont, 184, 48);
lv_obj_align(hum_cont, LV_ALIGN_TOP_MID, 100, 90);
lv_obj_set_style_bg_color(hum_cont, lv_color_hex(0x00b894), 0);
lv_obj_set_style_bg_grad_color(hum_cont, lv_color_hex(0x00a085), 0);
lv_obj_set_style_bg_grad_dir(hum_cont, LV_GRAD_DIR_HOR, 0);
lv_obj_set_style_radius(hum_cont, 10, 0);
lv_obj_t *hum_val = lv_label_create(hum_cont);
lv_label_set_text(hum_val, ("Hum\n" + String(current_weather.relative_humidity_2m) + "%").c_str());
lv_obj_set_style_text_font(hum_val, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(hum_val, lv_color_hex(0xFFFFFF), 0);
lv_obj_center(hum_val);
// Wind
lv_obj_t *wind_cont = lv_obj_create(weatherContainer);
lv_obj_set_size(wind_cont, 184, 48);
lv_obj_align(wind_cont, LV_ALIGN_TOP_MID, -100, 143);
lv_obj_set_style_bg_color(wind_cont, lv_color_hex(0xfd79a8), 0);
lv_obj_set_style_bg_grad_color(wind_cont, lv_color_hex(0xe84393), 0);
lv_obj_set_style_bg_grad_dir(wind_cont, LV_GRAD_DIR_HOR, 0);
lv_obj_set_style_radius(wind_cont, 10, 0);
lv_obj_t *wind_val = lv_label_create(wind_cont);
lv_label_set_text(wind_val, ("Wind\n" + String(round(current_weather.wind_speed_10m)) + " km/h").c_str());
lv_obj_set_style_text_font(wind_val, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(wind_val, lv_color_hex(0xFFFFFF), 0);
lv_obj_center(wind_val);
// Precip
lv_obj_t *precip_cont = lv_obj_create(weatherContainer);
lv_obj_set_size(precip_cont, 184, 48);
lv_obj_align(precip_cont, LV_ALIGN_TOP_MID, 100, 143);
lv_obj_set_style_bg_color(precip_cont, lv_color_hex(0x55a3ff), 0);
lv_obj_set_style_bg_grad_color(precip_cont, lv_color_hex(0x007acc), 0);
lv_obj_set_style_bg_grad_dir(precip_cont, LV_GRAD_DIR_HOR, 0);
lv_obj_set_style_radius(precip_cont, 10, 0);
lv_obj_t *precip_val = lv_label_create(precip_cont);
lv_label_set_text(precip_val, ("Precip\n" + String(current_weather.precipitation) + " mm").c_str());
lv_obj_set_style_text_font(precip_val, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(precip_val, lv_color_hex(0xFFFFFF), 0);
lv_obj_center(precip_val);
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
    lv_obj_set_size(eventContainer, 400, 175);
    lv_obj_align(eventContainer, LV_ALIGN_TOP_RIGHT, -10, 50);
    lv_obj_set_style_bg_color(eventContainer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_width(eventContainer, 0, 0);
    lv_obj_set_scrollbar_mode(eventContainer, LV_SCROLLBAR_MODE_OFF);
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
      lv_obj_t *event_cont = lv_obj_create(eventContainer);
      lv_obj_set_size(event_cont, 361, 57);
      lv_obj_align(event_cont, LV_ALIGN_TOP_MID, 0, y_offset);
      lv_obj_set_style_bg_color(event_cont, lv_color_hex(0xfd79a8), 0);
      lv_obj_set_style_bg_grad_color(event_cont, lv_color_hex(0xe84393), 0);
      lv_obj_set_style_bg_grad_dir(event_cont, LV_GRAD_DIR_HOR, 0);
      lv_obj_set_style_radius(event_cont, 10, 0);
      lv_obj_set_style_pad_all(event_cont, 5, 0);
      lv_obj_set_user_data(event_cont, (void*)(intptr_t)i);
      lv_obj_add_event_cb(event_cont, show_event_details_cb, LV_EVENT_CLICKED, NULL);
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
      lv_obj_t *eventLabel = lv_label_create(event_cont);
      lv_label_set_text(eventLabel, text.c_str());
      lv_obj_set_style_text_font(eventLabel, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(eventLabel, lv_color_hex(0xFFFFFF), 0);
      lv_obj_set_style_text_align(eventLabel, LV_TEXT_ALIGN_LEFT, 0);
      lv_label_set_long_mode(eventLabel, LV_LABEL_LONG_WRAP);
      lv_obj_set_width(eventLabel, lv_pct(100));
      lv_obj_center(eventLabel);
      y_offset += 67;
      displayed++;
    }
  }
  lv_obj_t *upcomingLabel = lv_label_create(eventContainer);
  lv_label_set_text(upcomingLabel, "");
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
          int days = (eventTime - todayStart) / 86400;
          lv_obj_t *event_cont = lv_obj_create(eventContainer);
          lv_obj_set_size(event_cont, 361, 57);
          lv_obj_align(event_cont, LV_ALIGN_TOP_MID, 0, y_offset);
          lv_obj_set_user_data(event_cont, (void*)(intptr_t)i);
          lv_obj_add_event_cb(event_cont, show_event_details_cb, LV_EVENT_CLICKED, NULL);
          if (days <= 3) {
            lv_obj_set_style_bg_color(event_cont, lv_color_hex(0xa29bfe), 0);
            lv_obj_set_style_bg_grad_color(event_cont, lv_color_hex(0x6c5ce7), 0);
          } else {
            lv_obj_set_style_bg_color(event_cont, lv_color_hex(0x00b894), 0);
            lv_obj_set_style_bg_grad_color(event_cont, lv_color_hex(0x00a085), 0);
          }
          lv_obj_set_style_bg_grad_dir(event_cont, LV_GRAD_DIR_HOR, 0);
          lv_obj_set_style_radius(event_cont, 10, 0);
          lv_obj_set_style_pad_all(event_cont, 5, 0);
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
          text += "\n";
          lv_obj_t *eventLabel = lv_label_create(event_cont);
          lv_label_set_text(eventLabel, text.c_str());
          lv_obj_set_style_text_font(eventLabel, &lv_font_montserrat_14, 0);
          lv_obj_set_style_text_color(eventLabel, lv_color_hex(0xFFFFFF), 0);
          lv_obj_set_style_text_align(eventLabel, LV_TEXT_ALIGN_LEFT, 0);
          lv_label_set_long_mode(eventLabel, LV_LABEL_LONG_WRAP);
          lv_obj_set_width(eventLabel, lv_pct(100));
          lv_obj_center(eventLabel);
          y_offset += 67;
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
  lv_obj_t *dropdown = static_cast<lv_obj_t *>(lv_event_get_user_data(e));
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
      preferences.begin("location", false);
      location = preferences.getString("location", "");
      preferences.end();
      if (location == "") {
        show_location_screen();
      } else {
        setup_calendar();
      }
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
  lv_obj_t *api_ta = static_cast<lv_obj_t *>(lv_event_get_user_data(e));
  apiCode = String(lv_textarea_get_text(api_ta));
  preferences.begin("api", false);
  preferences.putString("apiCode", apiCode);
  preferences.end();
  Serial.println("[APP] API code saved");
  lv_obj_add_flag(api_code_screen, LV_OBJ_FLAG_HIDDEN);
  preferences.begin("location", false);
  location = preferences.getString("location", "");
  preferences.end();
  if (location == "") {
    show_location_screen();
  } else {
    setup_calendar();
  }
}
void location_submit_cb(lv_event_t * e) {
  Serial.println("[APP] Location submit button clicked");
  lv_obj_t *location_ta = static_cast<lv_obj_t *>(lv_event_get_user_data(e));
  location = String(lv_textarea_get_text(location_ta));
  preferences.begin("location", false);
  preferences.putString("location", location);
  preferences.end();
  Serial.println("[APP] Location saved: " + location);
  // Geocode
  HTTPClient httpGeo;
  String geoUrl = "https://geocoding-api.open-meteo.com/v1/search?name=" + location + "&count=1&language=en&format=json";
  httpGeo.begin(geoUrl);
  int httpCodeGeo = httpGeo.GET();
  if (httpCodeGeo == HTTP_CODE_OK) {
    String payloadGeo = httpGeo.getString();
    JsonDocument docGeo;
    DeserializationError error = deserializeJson(docGeo, payloadGeo);
    if (!error) {
      JsonObject result = docGeo["results"][0];
      lat = String(result["latitude"].as<float>(), 6);
      lon = String(result["longitude"].as<float>(), 6);
      preferences.begin("location", false);
      preferences.putString("lat", lat);
      preferences.putString("lon", lon);
      preferences.end();
      Serial.println("[APP] Geocoded to lat: " + lat + ", lon: " + lon);
    } else {
      Serial.println("[APP] Geocode JSON parsing failed");
    }
  } else {
    Serial.println("[APP] Geocode request failed");
  }
  httpGeo.end();
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  if (location_screen) {
    lv_obj_add_flag(location_screen, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(location_screen);
    location_screen = nullptr;
  }
  setup_calendar();
}
void wifi_logout_cb(lv_event_t * e) {
  Serial.println("[APP] WiFi logout button clicked");
  preferences.begin("wifi", false);
  preferences.clear();
  preferences.end();
  Serial.println("[APP] WiFi credentials cleared");
  WiFi.disconnect();
  if (wifi_setup_screen) {
    lv_obj_del(wifi_setup_screen);
    wifi_setup_screen = nullptr;
  }
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  show_wifi_setup_screen();
}
void api_logout_cb(lv_event_t * e) {
  Serial.println("[APP] API logout button clicked");
  preferences.begin("api", false);
  preferences.clear();
  preferences.end();
  Serial.println("[APP] API code cleared");
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  show_api_code_screen();
}
void keyboard_event_cb(lv_event_t * e) {
  lv_event_code_t code = lv_event_get_code(e);
  lv_obj_t *ta = static_cast<lv_obj_t *>(lv_event_get_user_data(e));
  if (code == LV_EVENT_FOCUSED) {
    if (ta && keyboard) {
      lv_keyboard_set_textarea(keyboard, ta);
      lv_keyboard_set_mode(keyboard, (intptr_t)lv_obj_get_user_data(ta) ? LV_KEYBOARD_MODE_NUMBER : LV_KEYBOARD_MODE_TEXT_LOWER);
      lv_obj_clear_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
      if (new_event_popup) lv_obj_align(new_event_popup, LV_ALIGN_TOP_MID, 0, 0);
      if (settings_popup) lv_obj_align(settings_popup, LV_ALIGN_TOP_MID, 0, 0);
    } else {
      Serial.println("[APP] Error: Invalid textarea or keyboard in keyboard_event_cb");
    }
  } else if (code == LV_EVENT_DEFOCUSED) {
    if (keyboard) {
      lv_keyboard_set_textarea(keyboard, nullptr);
      lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
      if (new_event_popup) lv_obj_align(new_event_popup, LV_ALIGN_CENTER, 0, 0);
      if (settings_popup) lv_obj_align(settings_popup, LV_ALIGN_CENTER, 0, 0);
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
  if (ssid != "") {
    lv_obj_t *wifi_logout_btn = lv_button_create(wifi_setup_screen);
    lv_obj_add_event_cb(wifi_logout_btn, wifi_logout_cb, LV_EVENT_CLICKED, NULL);
    lv_obj_align(wifi_logout_btn, LV_ALIGN_TOP_MID, 0, 260);
    lv_obj_set_size(wifi_logout_btn, 120, 40);
    lv_obj_set_style_bg_color(wifi_logout_btn, lv_color_hex(0x333333), 0);
    lv_obj_t *wifi_logout_label = lv_label_create(wifi_logout_btn);
    lv_label_set_text(wifi_logout_label, "WiFi Logout");
    lv_obj_center(wifi_logout_label);
    lv_obj_set_style_text_font(wifi_logout_label, &lv_font_montserrat_14, 0);
  }
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
void show_location_screen() {
  Serial.println("[APP] Showing location screen...");
  location_screen = lv_obj_create(lv_scr_act());
  lv_obj_set_size(location_screen, 800, 480);
  lv_obj_set_style_bg_color(location_screen, lv_color_hex(0x000000), 0);
  lv_obj_t *location_ta = lv_textarea_create(location_screen);
  lv_textarea_set_one_line(location_ta, true);
  lv_textarea_set_placeholder_text(location_ta, "Enter location (Country,City)");
  lv_obj_set_width(location_ta, 300);
  lv_obj_align(location_ta, LV_ALIGN_TOP_MID, 0, 100);
  lv_obj_set_style_text_font(location_ta, &lv_font_montserrat_14, 0);
  lv_obj_add_event_cb(location_ta, keyboard_event_cb, LV_EVENT_FOCUSED, location_ta);
  lv_obj_add_event_cb(location_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, location_ta);
  lv_obj_t *submit_btn = lv_button_create(location_screen);
  lv_obj_align(submit_btn, LV_ALIGN_TOP_MID, 0, 170);
  lv_obj_set_size(submit_btn, 120, 40);
  lv_obj_add_event_cb(submit_btn, location_submit_cb, LV_EVENT_CLICKED, location_ta);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  keyboard = lv_keyboard_create(location_screen);
  lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
}
void close_settings_cb(lv_event_t * e) {
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
}
void show_settings_popup() {
  Serial.println("[APP] Showing settings popup...");
  settings_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(settings_popup, 600, 400);
  lv_obj_align(settings_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(settings_popup, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_color(settings_popup, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_border_width(settings_popup, 2, 0);
  // Version label in settings
  lv_obj_t *version_settings_label = lv_label_create(settings_popup);
  lv_label_set_text(version_settings_label, ("Version: " + currentFirmwareVersion).c_str());
  lv_obj_align(version_settings_label, LV_ALIGN_TOP_MID, 0, 10);
  lv_obj_set_style_text_font(version_settings_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(version_settings_label, lv_color_hex(0xFFFFFF), 0);
  lv_obj_t *wifi_logout_btn = lv_button_create(settings_popup);
  lv_obj_add_event_cb(wifi_logout_btn, wifi_logout_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_align(wifi_logout_btn, LV_ALIGN_TOP_MID, -100, 50);
  lv_obj_set_size(wifi_logout_btn, 120, 40);
  lv_obj_set_style_bg_color(wifi_logout_btn, lv_color_hex(0x333333), 0);
  lv_obj_t *wifi_logout_label = lv_label_create(wifi_logout_btn);
  lv_label_set_text(wifi_logout_label, "WiFi Logout");
  lv_obj_center(wifi_logout_label);
  lv_obj_set_style_text_font(wifi_logout_label, &lv_font_montserrat_14, 0);
  lv_obj_t *api_logout_btn = lv_button_create(settings_popup);
  lv_obj_add_event_cb(api_logout_btn, api_logout_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_align(api_logout_btn, LV_ALIGN_TOP_MID, 100, 50);
  lv_obj_set_size(api_logout_btn, 120, 40);
  lv_obj_set_style_bg_color(api_logout_btn, lv_color_hex(0x333333), 0);
  lv_obj_t *api_logout_label = lv_label_create(api_logout_btn);
  lv_label_set_text(api_logout_label, "API Logout");
  lv_obj_center(api_logout_label);
  lv_obj_set_style_text_font(api_logout_label, &lv_font_montserrat_14, 0);
  lv_obj_t *location_label = lv_label_create(settings_popup);
  lv_label_set_text(location_label, "Location:");
  lv_obj_align(location_label, LV_ALIGN_TOP_LEFT, 50, 100);
  lv_obj_set_style_text_font(location_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(location_label, lv_color_hex(0xFFFFFF), 0);
  lv_obj_t *location_ta = lv_textarea_create(settings_popup);
  lv_textarea_set_one_line(location_ta, true);
  lv_textarea_set_placeholder_text(location_ta, "Country,City");
  lv_textarea_set_text(location_ta, location.c_str());
  lv_obj_set_width(location_ta, 300);
  lv_obj_align(location_ta, LV_ALIGN_TOP_MID, 0, 100);
  lv_obj_set_style_text_font(location_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(location_ta, (void*)0);
  lv_obj_add_event_cb(location_ta, keyboard_event_cb, LV_EVENT_FOCUSED, location_ta);
  lv_obj_add_event_cb(location_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, location_ta);
  lv_obj_t *submit_btn = lv_button_create(settings_popup);
  lv_obj_align(submit_btn, LV_ALIGN_TOP_MID, 0, 150);
  lv_obj_set_size(submit_btn, 120, 40);
  lv_obj_add_event_cb(submit_btn, location_submit_cb, LV_EVENT_CLICKED, location_ta);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Update Location");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  // Close button
  lv_obj_t *close_btn = lv_button_create(settings_popup);
  lv_obj_align(close_btn, LV_ALIGN_BOTTOM_MID, 0, -20);
  lv_obj_set_size(close_btn, 40, 40);
  lv_obj_set_style_bg_color(close_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_add_event_cb(close_btn, close_settings_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_t *close_label = lv_label_create(close_btn);
  lv_label_set_text(close_label, LV_SYMBOL_CLOSE);
  lv_obj_center(close_label);
  lv_obj_set_style_text_font(close_label, &lv_font_montserrat_14, 0);
  // Refresh button in settings
  lv_obj_t *refresh_btn_settings = lv_button_create(settings_popup);
  lv_obj_add_event_cb(refresh_btn_settings, button_event_cb, LV_EVENT_CLICKED, calendar);
  lv_obj_align(refresh_btn_settings, LV_ALIGN_BOTTOM_LEFT, 10, -20);
  lv_obj_set_size(refresh_btn_settings, 40, 40);
  lv_obj_set_style_bg_color(refresh_btn_settings, lv_color_hex(0x333333), 0);
  lv_obj_t *refresh_label_settings = lv_label_create(refresh_btn_settings);
  lv_label_set_text(refresh_label_settings, LV_SYMBOL_REFRESH);
  lv_obj_center(refresh_label_settings);
  lv_obj_set_style_text_font(refresh_label_settings, &lv_font_montserrat_14, 0);
  keyboard = lv_keyboard_create(lv_scr_act());
  lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
  lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
}
void settings_btn_cb(lv_event_t * e) {
Serial.println("[APP] Settings button clicked");
lv_event_code_t code = lv_event_get_code(e);
if (code == LV_EVENT_CLICKED) {
if (!settings_popup) {
show_settings_popup();
}
} else if (code == LV_EVENT_LONG_PRESSED) {
if (settings_popup) {
close_settings_cb(e);
}
}
}
void new_event_btn_cb(lv_event_t * e) {
  Serial.println("[APP] New event button clicked");
  show_new_event_popup();
}
void show_new_event_popup(lv_calendar_date_t *selected_date) {
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
  lv_obj_set_user_data(ui->title_ta, (void*)0);
  lv_obj_add_event_cb(ui->title_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->title_ta);
  lv_obj_add_event_cb(ui->title_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->title_ta);
  ui->desc_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->desc_ta, false);
  lv_textarea_set_placeholder_text(ui->desc_ta, "Event description");
  lv_obj_set_size(ui->desc_ta, 500, 80);
  lv_obj_align(ui->desc_ta, LV_ALIGN_TOP_MID, 0, 70);
  lv_obj_set_style_text_font(ui->desc_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(ui->desc_ta, lv_color_hex(0x333333), 0); // Darker text
  lv_obj_set_user_data(ui->desc_ta, (void*)0);
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
  int start_year, start_month, start_day;
  if (selected_date) {
    start_year = selected_date->year;
    start_month = selected_date->month;
    start_day = selected_date->day;
  } else {
    start_year = timeinfo.tm_year + 1900;
    start_month = timeinfo.tm_mon + 1;
    start_day = timeinfo.tm_mday;
  }
  int start_hour = timeinfo.tm_hour;
  int start_min = (timeinfo.tm_min / 15) * 15;
  struct tm start_tm = timeinfo;
  start_tm.tm_year = start_year - 1900;
  start_tm.tm_mon = start_month - 1;
  start_tm.tm_mday = start_day;
  start_tm.tm_hour = start_hour;
  start_tm.tm_min = start_min;
  start_tm.tm_sec = 0;
  time_t start_time = mktime(&start_tm);
  time_t end_time = start_time + 15 * 60;
  struct tm *end_tm = localtime(&end_time);
  int end_year = end_tm->tm_year + 1900;
  int end_month = end_tm->tm_mon + 1;
  int end_day = end_tm->tm_mday;
  int end_hour = end_tm->tm_hour;
  int end_min = (end_tm->tm_min / 15) * 15;
  lv_obj_t *start_label = lv_label_create(new_event_popup);
  lv_label_set_text(start_label, "Start:");
  lv_obj_align(start_label, LV_ALIGN_TOP_LEFT, 50, 170);
  lv_obj_set_style_text_font(start_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(start_label, lv_color_hex(0xFFFFFF), 0); // Brighter text
  char buf[5];
  ui->start_year_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->start_year_ta, true);
  lv_textarea_set_max_length(ui->start_year_ta, 4);
  snprintf(buf, sizeof(buf), "%04d", start_year);
  lv_textarea_set_text(ui->start_year_ta, buf);
  lv_textarea_set_placeholder_text(ui->start_year_ta, "YYYY");
  lv_obj_set_width(ui->start_year_ta, 100);
  lv_obj_align(ui->start_year_ta, LV_ALIGN_TOP_LEFT, 100, 170);
  lv_obj_set_style_text_font(ui->start_year_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->start_year_ta, (void*)1);
  lv_obj_add_event_cb(ui->start_year_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->start_year_ta);
  lv_obj_add_event_cb(ui->start_year_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->start_year_ta);
  ui->start_month_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->start_month_ta, true);
  lv_textarea_set_max_length(ui->start_month_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", start_month);
  lv_textarea_set_text(ui->start_month_ta, buf);
  lv_textarea_set_placeholder_text(ui->start_month_ta, "MM");
  lv_obj_set_width(ui->start_month_ta, 60);
  lv_obj_align(ui->start_month_ta, LV_ALIGN_TOP_LEFT, 210, 170);
  lv_obj_set_style_text_font(ui->start_month_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->start_month_ta, (void*)1);
  lv_obj_add_event_cb(ui->start_month_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->start_month_ta);
  lv_obj_add_event_cb(ui->start_month_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->start_month_ta);
  ui->start_day_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->start_day_ta, true);
  lv_textarea_set_max_length(ui->start_day_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", start_day);
  lv_textarea_set_text(ui->start_day_ta, buf);
  lv_textarea_set_placeholder_text(ui->start_day_ta, "DD");
  lv_obj_set_width(ui->start_day_ta, 60);
  lv_obj_align(ui->start_day_ta, LV_ALIGN_TOP_LEFT, 280, 170);
  lv_obj_set_style_text_font(ui->start_day_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->start_day_ta, (void*)1);
  lv_obj_add_event_cb(ui->start_day_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->start_day_ta);
  lv_obj_add_event_cb(ui->start_day_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->start_day_ta);
  ui->start_hour_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->start_hour_ta, true);
  lv_textarea_set_max_length(ui->start_hour_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", start_hour);
  lv_textarea_set_text(ui->start_hour_ta, buf);
  lv_textarea_set_placeholder_text(ui->start_hour_ta, "HH");
  lv_obj_set_width(ui->start_hour_ta, 60);
  lv_obj_align(ui->start_hour_ta, LV_ALIGN_TOP_LEFT, 350, 170);
  lv_obj_set_style_text_font(ui->start_hour_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->start_hour_ta, (void*)1);
  lv_obj_add_event_cb(ui->start_hour_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->start_hour_ta);
  lv_obj_add_event_cb(ui->start_hour_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->start_hour_ta);
  ui->start_min_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->start_min_ta, true);
  lv_textarea_set_max_length(ui->start_min_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", start_min);
  lv_textarea_set_text(ui->start_min_ta, buf);
  lv_textarea_set_placeholder_text(ui->start_min_ta, "MM");
  lv_obj_set_width(ui->start_min_ta, 60);
  lv_obj_align(ui->start_min_ta, LV_ALIGN_TOP_LEFT, 420, 170);
  lv_obj_set_style_text_font(ui->start_min_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->start_min_ta, (void*)1);
  lv_obj_add_event_cb(ui->start_min_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->start_min_ta);
  lv_obj_add_event_cb(ui->start_min_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->start_min_ta);
  lv_obj_t *end_label = lv_label_create(new_event_popup);
  lv_label_set_text(end_label, "End:");
  lv_obj_align(end_label, LV_ALIGN_TOP_LEFT, 50, 220);
  lv_obj_set_style_text_font(end_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(end_label, lv_color_hex(0xFFFFFF), 0); // Brighter text
  ui->end_year_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->end_year_ta, true);
  lv_textarea_set_max_length(ui->end_year_ta, 4);
  snprintf(buf, sizeof(buf), "%04d", end_year);
  lv_textarea_set_text(ui->end_year_ta, buf);
  lv_textarea_set_placeholder_text(ui->end_year_ta, "YYYY");
  lv_obj_set_width(ui->end_year_ta, 100);
  lv_obj_align(ui->end_year_ta, LV_ALIGN_TOP_LEFT, 100, 220);
  lv_obj_set_style_text_font(ui->end_year_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->end_year_ta, (void*)1);
  lv_obj_add_event_cb(ui->end_year_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->end_year_ta);
  lv_obj_add_event_cb(ui->end_year_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->end_year_ta);
  ui->end_month_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->end_month_ta, true);
  lv_textarea_set_max_length(ui->end_month_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", end_month);
  lv_textarea_set_text(ui->end_month_ta, buf);
  lv_textarea_set_placeholder_text(ui->end_month_ta, "MM");
  lv_obj_set_width(ui->end_month_ta, 60);
  lv_obj_align(ui->end_month_ta, LV_ALIGN_TOP_LEFT, 210, 220);
  lv_obj_set_style_text_font(ui->end_month_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->end_month_ta, (void*)1);
  lv_obj_add_event_cb(ui->end_month_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->end_month_ta);
  lv_obj_add_event_cb(ui->end_month_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->end_month_ta);
  ui->end_day_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->end_day_ta, true);
  lv_textarea_set_max_length(ui->end_day_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", end_day);
  lv_textarea_set_text(ui->end_day_ta, buf);
  lv_textarea_set_placeholder_text(ui->end_day_ta, "DD");
  lv_obj_set_width(ui->end_day_ta, 60);
  lv_obj_align(ui->end_day_ta, LV_ALIGN_TOP_LEFT, 280, 220);
  lv_obj_set_style_text_font(ui->end_day_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->end_day_ta, (void*)1);
  lv_obj_add_event_cb(ui->end_day_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->end_day_ta);
  lv_obj_add_event_cb(ui->end_day_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->end_day_ta);
  ui->end_hour_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->end_hour_ta, true);
  lv_textarea_set_max_length(ui->end_hour_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", end_hour);
  lv_textarea_set_text(ui->end_hour_ta, buf);
  lv_textarea_set_placeholder_text(ui->end_hour_ta, "HH");
  lv_obj_set_width(ui->end_hour_ta, 60);
  lv_obj_align(ui->end_hour_ta, LV_ALIGN_TOP_LEFT, 350, 220);
  lv_obj_set_style_text_font(ui->end_hour_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->end_hour_ta, (void*)1);
  lv_obj_add_event_cb(ui->end_hour_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->end_hour_ta);
  lv_obj_add_event_cb(ui->end_hour_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->end_hour_ta);
  ui->end_min_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->end_min_ta, true);
  lv_textarea_set_max_length(ui->end_min_ta, 2);
  snprintf(buf, sizeof(buf), "%02d", end_min);
  lv_textarea_set_text(ui->end_min_ta, buf);
  lv_textarea_set_placeholder_text(ui->end_min_ta, "MM");
  lv_obj_set_width(ui->end_min_ta, 60);
  lv_obj_align(ui->end_min_ta, LV_ALIGN_TOP_LEFT, 420, 220);
  lv_obj_set_style_text_font(ui->end_min_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->end_min_ta, (void*)1);
  lv_obj_add_event_cb(ui->end_min_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->end_min_ta);
  lv_obj_add_event_cb(ui->end_min_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->end_min_ta);
  lv_obj_t *remind_label = lv_label_create(new_event_popup);
  lv_label_set_text(remind_label, "Remind before (5m,2h,1d):");
  lv_obj_align(remind_label, LV_ALIGN_TOP_LEFT, 50, 270);
  lv_obj_set_style_text_font(remind_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(remind_label, lv_color_hex(0xFFFFFF), 0); // Brighter text
  ui->remind_before_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->remind_before_ta, true);
  lv_textarea_set_max_length(ui->remind_before_ta, 4);
  lv_textarea_set_placeholder_text(ui->remind_before_ta, "0m");
  lv_textarea_set_text(ui->remind_before_ta, "0m");
  lv_obj_set_width(ui->remind_before_ta, 100);
  lv_obj_align(ui->remind_before_ta, LV_ALIGN_TOP_LEFT, 250, 270);
  lv_obj_set_style_text_font(ui->remind_before_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->remind_before_ta, (void*)0);
  lv_obj_add_event_cb(ui->remind_before_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->remind_before_ta);
  lv_obj_add_event_cb(ui->remind_before_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->remind_before_ta);
  lv_obj_t *submit_btn = lv_button_create(new_event_popup);
  lv_obj_align(submit_btn, LV_ALIGN_TOP_MID, -70, 320);
  lv_obj_set_size(submit_btn, 120, 40);
  lv_obj_set_style_bg_color(submit_btn, lv_color_hex(0x00FF00), 0);
  lv_obj_add_event_cb(submit_btn, new_event_submit_cb, LV_EVENT_CLICKED, ui);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  lv_obj_t *cancel_btn = lv_button_create(new_event_popup);
  lv_obj_align(cancel_btn, LV_ALIGN_TOP_MID, 70, 320);
  lv_obj_set_size(cancel_btn, 120, 40);
  lv_obj_set_style_bg_color(cancel_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_add_event_cb(cancel_btn, new_event_cancel_cb, LV_EVENT_CLICKED, ui);
  lv_obj_t *cancel_label = lv_label_create(cancel_btn);
  lv_label_set_text(cancel_label, "Cancel");
  lv_obj_center(cancel_label);
  lv_obj_set_style_text_font(cancel_label, &lv_font_montserrat_14, 0);
  keyboard = lv_keyboard_create(lv_scr_act());
  lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
  lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
}
void new_event_submit_cb(lv_event_t * e) {
  Serial.println("[APP] New event submit button clicked");
  NewEventUI *ui = static_cast<NewEventUI *>(lv_event_get_user_data(e));
  if (!ui) {
    Serial.println("[APP] Error: UI structure is null");
    lv_obj_t *error_label = lv_label_create(new_event_popup);
    lv_label_set_text(error_label, "Internal error");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 320);
    return;
  }
  if (!ui->title_ta || !ui->desc_ta || !ui->start_year_ta || !ui->start_month_ta ||
      !ui->start_day_ta || !ui->start_hour_ta || !ui->start_min_ta ||
      !ui->end_year_ta || !ui->end_month_ta || !ui->end_day_ta ||
      !ui->end_hour_ta || !ui->end_min_ta || !ui->remind_before_ta) {
    Serial.println("[APP] Error: One or more UI elements are null");
    lv_obj_t *error_label = lv_label_create(new_event_popup);
    lv_label_set_text(error_label, "Internal error");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 320);
    return;
  }
  String title = String(lv_textarea_get_text(ui->title_ta));
  String description = String(lv_textarea_get_text(ui->desc_ta));
  String start_year = String(lv_textarea_get_text(ui->start_year_ta));
  String start_month = String(lv_textarea_get_text(ui->start_month_ta));
  if (start_month.length() == 1) start_month = "0" + start_month;
  String start_day = String(lv_textarea_get_text(ui->start_day_ta));
  if (start_day.length() == 1) start_day = "0" + start_day;
  String start_hour = String(lv_textarea_get_text(ui->start_hour_ta));
  if (start_hour.length() == 1) start_hour = "0" + start_hour;
  String start_min = String(lv_textarea_get_text(ui->start_min_ta));
  if (start_min.length() == 1) start_min = "0" + start_min;
  String end_year = String(lv_textarea_get_text(ui->end_year_ta));
  String end_month = String(lv_textarea_get_text(ui->end_month_ta));
  if (end_month.length() == 1) end_month = "0" + end_month;
  String end_day = String(lv_textarea_get_text(ui->end_day_ta));
  if (end_day.length() == 1) end_day = "0" + end_day;
  String end_hour = String(lv_textarea_get_text(ui->end_hour_ta));
  if (end_hour.length() == 1) end_hour = "0" + end_hour;
  String end_min = String(lv_textarea_get_text(ui->end_min_ta));
  if (end_min.length() == 1) end_min = "0" + end_min;
  String remind_before = String(lv_textarea_get_text(ui->remind_before_ta));
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
  String encoded_remind_before = "";
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
  for (char c : remind_before) {
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded_remind_before += c;
    } else {
      char hex[4];
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded_remind_before += hex;
    }
  }
  String url1 = "https://breezbee.co.uk/api/api.php?unique_code=" + apiCode +
               "&title=" + encoded_title +
               "&message_description=" + encoded_description +
               "&from=" + encoded_from +
               "&to=" + encoded_to +
               "&remind_before=" + encoded_remind_before;
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
  NewEventUI *ui = static_cast<NewEventUI *>(lv_event_get_user_data(e));
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
  strftime(date_time_str, sizeof(date_time_str), "%Y-%m-%d %H:%M", &timeinfo);
  lv_label_set_text(date_time_label, date_time_str);
  Serial.println("[APP] Updated date-time label to: " + String(date_time_str));
}
void updateMonthLabel(lv_obj_t *calendar) {
  if (!calendar) {
    Serial.println("[APP] Error: Calendar object is null in updateMonthLabel");
    if (month_label) {
      lv_label_set_text(month_label, "Unknown Month");
    }
    return;
  }
  if (!month_label) {
    Serial.println("[APP] Error: month_label is null in updateMonthLabel");
    return;
  }
  const lv_calendar_date_t *showed = lv_calendar_get_showed_date(calendar);
  if (!showed) {
    Serial.println("[APP] Error: Failed to get showed date");
    return;
  }
  struct tm tm;
  memset(&tm, 0, sizeof(tm));
  tm.tm_year = showed->year - 1900;
  tm.tm_mon = showed->month - 1;
  tm.tm_mday = 1;
  char buf[32];
  strftime(buf, sizeof(buf), "%B %Y", &tm);
  lv_label_set_text(month_label, buf);
  Serial.println("[APP] Updated month label to: " + String(buf));
}
void checkFirmwareUpdate() {
  Serial.println("[OTA] Starting firmware update check...");
  printMemoryUsage();
  // Check WiFi status
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[OTA] WiFi not connected (status: " + String(WiFi.status()) + "), skipping update check");
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
    http.end();
    return;
  }
  Serial.println("[OTA] HTTP request successful (200 OK)");
  // Parse JSON response
  String payload = http.getString();
  Serial.println("[OTA] Response payload: " + payload);
  JsonDocument doc;
  DeserializationError error = deserializeJson(doc, payload);
  if (error) {
    Serial.println("[OTA] JSON parsing failed: " + String(error.c_str()));
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
  if (firmware_update_btn) {
    lv_obj_add_flag(firmware_update_btn, LV_OBJ_FLAG_HIDDEN);
    Serial.println("[OTA] Update button hidden before update");
  }
  updateFirmware();
}
// Modified update_btn_cb function
void update_btn_cb(lv_event_t *e) {
  Serial.println("[OTA] Update button clicked");
  if (firmware_update_btn) {
    lv_obj_add_flag(firmware_update_btn, LV_OBJ_FLAG_HIDDEN); // Hide update button
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
static int showed_year;
static int showed_month;
void prev_month_cb(lv_event_t *e) {
  const lv_calendar_date_t * showed = lv_calendar_get_showed_date(calendar);
  showed_month = showed->month;
  showed_year = showed->year;
  showed_month--;
  if (showed_month < 1) {
    showed_month = 12;
    showed_year--;
  }
  lv_calendar_set_showed_date(calendar, showed_year, showed_month);
  updateMonthLabel(calendar);
}
void next_month_cb(lv_event_t *e) {
  const lv_calendar_date_t * showed = lv_calendar_get_showed_date(calendar);
  showed_month = showed->month;
  showed_year = showed->year;
  showed_month++;
  if (showed_month > 12) {
    showed_month = 1;
    showed_year++;
  }
  lv_calendar_set_showed_date(calendar, showed_year, showed_month);
  updateMonthLabel(calendar);
}
void updateFirmwareButton() {
  if (latestFirmwareVersion != "" && latestFirmwareVersion != currentFirmwareVersion) {
    if (!firmware_update_btn) {
      firmware_update_btn = lv_button_create(button_bar);
      lv_obj_add_event_cb(firmware_update_btn, update_btn_cb, LV_EVENT_CLICKED, NULL);
      lv_obj_set_style_bg_color(firmware_update_btn, lv_color_hex(0x00008B), 0);
      lv_obj_t *update_label = lv_label_create(firmware_update_btn);
      lv_label_set_text(update_label, LV_SYMBOL_DOWNLOAD);
      lv_obj_center(update_label);
      lv_obj_set_style_text_font(update_label, &lv_font_montserrat_14, 0);
      lv_obj_set_size(firmware_update_btn, 40, 40);
      lv_obj_move_to_index(firmware_update_btn, 1); // Insert after settings button
    }
  } else {
    if (firmware_update_btn) {
      lv_obj_del(firmware_update_btn);
      firmware_update_btn = nullptr;
    }
  }
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
  showed_year = timeinfo.tm_year + 1900;
  showed_month = timeinfo.tm_mon + 1;
  lv_calendar_set_showed_date(calendar, showed_year, showed_month);
  lv_obj_add_event_cb(calendar, calendar_event_cb, LV_EVENT_VALUE_CHANGED, NULL);
  static lv_style_t style_highlight;
  lv_style_init(&style_highlight);
  lv_style_set_bg_color(&style_highlight, lv_color_hex(0xFF0000));
  lv_obj_add_style(calendar, &style_highlight, LV_PART_ITEMS | LV_STATE_CHECKED);
  Serial.println("[APP] Creating month label...");
  month_label = lv_label_create(lv_scr_act());
  lv_obj_set_style_text_font(month_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(month_label, lv_color_hex(0x000000), 0);
  lv_obj_align_to(month_label, calendar, LV_ALIGN_OUT_TOP_MID, 0, -10);
  Serial.println("[APP] Creating date-time label...");
  date_time_label = lv_label_create(lv_scr_act());
  lv_obj_set_style_text_font(date_time_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(date_time_label, lv_color_hex(0x000000), 0);
  lv_obj_align(date_time_label, LV_ALIGN_TOP_MID, 0, 10);
  updateDateTimeLabel();
  updateMonthLabel(calendar);
  Serial.println("[APP] Fetching initial events...");
  fetchEvents();
  updateEventDisplay(calendar);
  Serial.println("[APP] Fetching initial weather...");
  fetchWeather();
  updateWeatherDisplay();
  Serial.println("[APP] Checking for firmware updates...");
  checkFirmwareUpdate();
  // Create button bar for horizontal alignment
  button_bar = lv_obj_create(lv_scr_act());
  lv_obj_set_size(button_bar, 350, 40);
  lv_obj_align_to(button_bar, calendar, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
  lv_obj_set_flex_flow(button_bar, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(button_bar, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_set_style_bg_opa(button_bar, LV_OPA_TRANSP, 0);
  lv_obj_set_style_pad_all(button_bar, 0, 0);
  lv_obj_set_style_border_width(button_bar, 0, 0);
  lv_obj_set_scrollbar_mode(button_bar, LV_SCROLLBAR_MODE_OFF);
  // Create settings button always
  lv_obj_t *settings_btn_obj = lv_button_create(button_bar);
  lv_obj_add_event_cb(settings_btn_obj, settings_btn_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_set_style_bg_color(settings_btn_obj, lv_color_hex(0x333333), 0);
  lv_obj_t *settings_label = lv_label_create(settings_btn_obj);
  lv_label_set_text(settings_label, LV_SYMBOL_SETTINGS);
  lv_obj_center(settings_label);
  lv_obj_set_style_text_font(settings_label, &lv_font_montserrat_14, 0);
  lv_obj_set_size(settings_btn_obj, 40, 40);
  // Create previous button
  lv_obj_t *prev_btn = lv_button_create(button_bar);
  lv_obj_add_event_cb(prev_btn, prev_month_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_set_size(prev_btn, 40, 40);
  lv_obj_set_style_bg_color(prev_btn, lv_color_hex(0x333333), 0);
  lv_obj_t *prev_label = lv_label_create(prev_btn);
  lv_label_set_text(prev_label, LV_SYMBOL_LEFT);
  lv_obj_center(prev_label);
  lv_obj_set_style_text_font(prev_label, &lv_font_montserrat_14, 0);
  // Create next button
  lv_obj_t *next_btn = lv_button_create(button_bar);
  lv_obj_add_event_cb(next_btn, next_month_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_set_size(next_btn, 40, 40);
  lv_obj_set_style_bg_color(next_btn, lv_color_hex(0x333333), 0);
  lv_obj_t *next_label = lv_label_create(next_btn);
  lv_label_set_text(next_label, LV_SYMBOL_RIGHT);
  lv_obj_center(next_label);
  lv_obj_set_style_text_font(next_label, &lv_font_montserrat_14, 0);
  updateFirmwareButton();
  Serial.println("[APP] Setup complete");
  printMemoryUsage();
}
void button_event_cb(lv_event_t * e) {
  Serial.println("[APP] Refresh button clicked");
  lv_obj_t * btn = static_cast<lv_obj_t *>(lv_event_get_target(e));
  lv_obj_t * label = lv_obj_get_child(btn, 0);
  static bool toggle = false;
  toggle = !toggle;
  lv_label_set_text(label, toggle ? LV_SYMBOL_REFRESH : LV_SYMBOL_REFRESH);
  fetchEvents();
  updateEventDisplay(static_cast<lv_obj_t *>(lv_event_get_user_data(e)));
  fetchWeather();
  updateWeatherDisplay();
  updateMonthLabel(static_cast<lv_obj_t *>(lv_event_get_user_data(e)));
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
  lv_obj_t *target = static_cast<lv_obj_t *>(lv_event_get_target(e));
  if (!target) {
    Serial.println("[APP] Error: Event target is null");
    return;
  }
  Serial.println("[APP] Retrieving pressed date...");
  lv_calendar_date_t date;
  if (!lv_calendar_get_pressed_date(calendar, &date)) {
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
  updateMonthLabel(calendar);
  show_new_event_popup(&date);
}
void show_event_details_cb(lv_event_t *e) {
  lv_obj_t *target = static_cast<lv_obj_t *>(lv_event_get_target(e));
  int index = (intptr_t)lv_obj_get_user_data(target);
  show_event_details(index);
}
void close_event_details_cb(lv_event_t *e) {
  if (event_details_popup) {
    lv_obj_add_flag(event_details_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(event_details_popup);
    event_details_popup = nullptr;
  }
}
void cancel_reminder_cb(lv_event_t *e) {
  int index = (intptr_t)lv_event_get_user_data(e);
  events[index].notified = true;
  close_event_details_cb(e);
}
void show_event_details(int index) {
  if (event_details_popup) {
    return; // Avoid multiple popups
  }
  if (index < 0 || index >= numEvents) {
    Serial.println("[APP] Invalid event index for details popup");
    return;
  }
  event_details_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(event_details_popup, 600, 400);
  lv_obj_align(event_details_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(event_details_popup, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_color(event_details_popup, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_border_width(event_details_popup, 2, 0);
  // Title
  lv_obj_t *title_label = lv_label_create(event_details_popup);
  lv_label_set_text(title_label, events[index].summary.c_str());
  lv_obj_align(title_label, LV_ALIGN_TOP_MID, 0, 10);
  lv_obj_set_style_text_font(title_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(title_label, lv_color_hex(0xFFFFFF), 0);
  // Description
  lv_obj_t *desc_label = lv_label_create(event_details_popup);
  lv_label_set_text(desc_label, events[index].description.c_str());
  lv_label_set_long_mode(desc_label, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(desc_label, 500);
  lv_obj_align(desc_label, LV_ALIGN_TOP_MID, 0, 50);
  lv_obj_set_style_text_font(desc_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(desc_label, lv_color_hex(0xFFFFFF), 0);
  // Start
  lv_obj_t *start_label = lv_label_create(event_details_popup);
  lv_label_set_text(start_label, ("Start: " + events[index].start).c_str());
  lv_obj_align_to(start_label, desc_label, LV_ALIGN_OUT_BOTTOM_MID, 0, 20);
  lv_obj_set_style_text_font(start_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(start_label, lv_color_hex(0xFFFFFF), 0);
  // End
  lv_obj_t *end_label = lv_label_create(event_details_popup);
  lv_label_set_text(end_label, ("End: " + events[index].end).c_str());
  lv_obj_align_to(end_label, start_label, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
  lv_obj_set_style_text_font(end_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(end_label, lv_color_hex(0xFFFFFF), 0);
  // Snooze button
  lv_obj_t *snooze_btn = lv_button_create(event_details_popup);
  lv_obj_align(snooze_btn, LV_ALIGN_BOTTOM_LEFT, 20, -20);
  lv_obj_set_size(snooze_btn, 120, 40);
  lv_obj_set_style_bg_color(snooze_btn, lv_color_hex(0x00FF00), 0);
  lv_obj_add_event_cb(snooze_btn, close_event_details_cb, LV_EVENT_CLICKED, NULL);
  lv_obj_t *snooze_label = lv_label_create(snooze_btn);
  lv_label_set_text(snooze_label, "Snooze");
  lv_obj_center(snooze_label);
  lv_obj_set_style_text_font(snooze_label, &lv_font_montserrat_14, 0);
  // Cancel button
  lv_obj_t *cancel_btn = lv_button_create(event_details_popup);
  lv_obj_align(cancel_btn, LV_ALIGN_BOTTOM_RIGHT, -20, -20);
  lv_obj_set_size(cancel_btn, 120, 40);
  lv_obj_set_style_bg_color(cancel_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_add_event_cb(cancel_btn, cancel_reminder_cb, LV_EVENT_CLICKED, (void*)(intptr_t)index);
  lv_obj_t *cancel_label = lv_label_create(cancel_btn);
  lv_label_set_text(cancel_label, "Cancel");
  lv_obj_center(cancel_label);
  lv_obj_set_style_text_font(cancel_label, &lv_font_montserrat_14, 0);
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
        preferences.begin("location", false);
        location = preferences.getString("location", "");
        lat = preferences.getString("lat", "");
        lon = preferences.getString("lon", "");
        preferences.end();
        if (location == "") {
          setup_display();
          show_location_screen();
        } else {
          setup_calendar();
        }
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
    lastRefreshTime = currentTime;
  }
  if (currentTime - lastFirmwareCheck >= firmwareCheckInterval && WiFi.status() == WL_CONNECTED) {
    checkFirmwareUpdate();
    updateFirmwareButton();
    lastFirmwareCheck = currentTime;
  }
  if (currentTime - lastDateTimeUpdate >= dateTimeUpdateInterval) {
    updateDateTimeLabel();
    time_t now;
    time(&now);
    for (int i = 0; i < numEvents; i++) {
      time_t reminder_time = events[i].start_time - events[i].remind_before * 60;
      if (now >= reminder_time && now < events[i].start_time && events[i].reminder_count < 3) {
        if (events[i].reminder_count == 0 || (now - events[i].last_reminder_time >= 300)) {
          show_event_details(i);
          events[i].reminder_count++;
          events[i].last_reminder_time = now;
          if (events[i].reminder_count == 3) {
            events[i].notified = true;
          }
        }
      }
    }
    lastDateTimeUpdate = currentTime;
  }
  if (currentTime - lastWeatherUpdate >= weatherUpdateInterval && WiFi.status() == WL_CONNECTED) {
    fetchWeather();
    updateWeatherDisplay();
    lastWeatherUpdate = currentTime;
  }
}