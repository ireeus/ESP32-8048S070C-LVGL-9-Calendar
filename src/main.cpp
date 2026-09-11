#include "display.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <time.h>
#include <Preferences.h>
#include <Update.h>
#include <WiFiClientSecure.h>
#include <esp_system.h>  // For ESP.restart()
extern const lv_font_t technology_98;
// Build version
const String build_version = "1.4";
int debug =0; // Change to 1 to enable serial prints
// Firmware check interval variable
const unsigned long firmwareCheckInterval = 100000UL; // 5 minutes in milliseconds
// Forward declarations
void fetchEvents();
void updateEventDisplay(lv_obj_t *calendar);
void blink_time_update_cb(lv_timer_t *timer);
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
void show_event_details(int index, bool isReminder);
void show_event_details_cb(lv_event_t *e);
void close_event_details_cb(lv_event_t *e);
void cancel_reminder_cb(lv_event_t *e);
void prev_month_cb(lv_event_t *e);
void next_month_cb(lv_event_t *e);
void updateFirmwareButton();
unsigned long hashString(const String& str);
void update_today_highlight(lv_obj_t *cal);
void darkness_slider_cb(lv_event_t *e); // New callback for darkness slider
void rearrange_calendar_parts(lv_obj_t *cal);
void fetchNotifications(); // New function for fetching notifications and displaying image
void save_settings_cb(lv_event_t *e); // Renamed and modified from location_submit_cb
void notification_click_cb(lv_event_t *e);
void blink_animation_cb(void * var, int32_t v);
void snooze_reminder_cb(lv_event_t *e);
const lv_image_dsc_t* getWifiImage();
void device_id_preview_cb(lv_event_t *e);
void fetchParcelBoxCredentials(); // New function to fetch parcelbox credentials
void fetchBankHolidays(); // New function to fetch bank holidays
void updateHolidayLabel(); // New function to update holiday label
void notification_toggle_cb(lv_timer_t *timer);




void blink_time_update_cb(lv_timer_t *timer) {
  lv_obj_t *colon = (lv_obj_t *)lv_timer_get_user_data(timer);
  if (!colon) return;

  lv_obj_t *parent = lv_obj_get_parent(colon);
  if (!parent) return;

  // Access siblings by creation order (0: hours, 1: colon, 2: minutes)
  lv_obj_t *hours = lv_obj_get_child(parent, 0);
  lv_obj_t *minutes = lv_obj_get_child(parent, 2);
  if (!hours || !minutes) return;

  // Toggle colon visibility (1s on, 1s off)
  static bool visible = true;
  lv_obj_set_style_text_opa(colon, visible ? LV_OPA_COVER : LV_OPA_TRANSP, 0);
  visible = !visible;

  // Update time display
  time_t now_t;
  time(&now_t);
  struct tm *timeinfo = localtime(&now_t);
  char hours_buf[3];
  strftime(hours_buf, sizeof(hours_buf), "%H", timeinfo);
  lv_label_set_text(hours, hours_buf);

  char mins_buf[3];
  strftime(mins_buf, sizeof(mins_buf), "%M", timeinfo);
  lv_label_set_text(minutes, mins_buf);
}
void fetchBackgroundFilename(); // New function to fetch background filename from server
void fetchAndSetBackgroundImage(); // New function to download and set background image
void fetchWeatherLocation();// forward declaration for new function
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
static lv_obj_t *holiday_label = nullptr; // New: Label for holiday description
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
static lv_obj_t *notification_img = nullptr; // New: Object for the notification image
static lv_obj_t *wifi_icon = nullptr; // New: Object for the WiFi signal icon
static bool notification_visible = false;
static lv_timer_t *notification_timer = NULL;
static lv_timer_t *blink_timer = NULL;
static lv_obj_t *bg_img = nullptr;  // Global for background image
static int g_ui_darkness = 0; // Global darkness level (0: light, 100: dark)
static int temp_adjust = 0;// Global temperature adjustment
static lv_obj_t *build_version_label = nullptr;// Global variable for build version label
static unsigned long lastHolidayUpdate = 0;
const unsigned long holidayUpdateInterval = 2592000000UL; // 30 days in milliseconds


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
// New: Struct for next half-hour weather (only code needed for condition)
struct NextWeather {
  int weather_code;
};
NextWeather next_weather;
// Structure to hold settings UI elements
struct SettingsUI {
  lv_obj_t *location_ta; // For weather location (city name)
  lv_obj_t *username_ta; // For parcelBox username
  lv_obj_t *device_id_ta; // For parcelBox device ID
  lv_obj_t *temp_adjust_ta; // For temperature adjustment
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
// Structure for bank holidays
struct Holiday {
  String title;
  int year;
  int month;
  int day;
};
Holiday holidays[100];
int numHolidays = 0;
// Time zone for London (BST/GMT)
const char* ntpServer = "pool.ntp.org";
// WiFi and API code
String ssid;
String password;
String apiCode;
String location;
String lat;
String lon;
String username;
String device_id;
String last_ignored_notification = "";
String current_notification_text = "";
String backgroundFilename = ""; // New: Store the background image filename
// OTA variables
String currentFirmwareVersion = "1.0.0"; // Loaded from Preferences in setup()
String latestFirmwareVersion = "";
String firmwareUrl = "";
WiFiClientSecure client;
// Global flag to indicate OTA is in progress
bool is_ota_updating = false;
////////////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////////////////////
// Auto-refresh timer
static unsigned long lastRefreshTime = 0;
const unsigned long refreshInterval = 60000;
// Timer for updating date-time label
static unsigned long lastDateTimeUpdate = 0;
const unsigned long dateTimeUpdateInterval = 60000; // Update every 1 minute (changed from 1000)
// Timer for updating weather
static unsigned long lastWeatherUpdate = 0;
const unsigned long weatherUpdateInterval = 900000; // Update every 15min
// Timer for firmware check
static unsigned long lastFirmwareCheck = 0;
// New: Timer for notification check
static unsigned long lastNotificationCheck = 0;
const unsigned long notificationInterval = 10000UL; // Check every 10 seconds
// New: Timer for WiFi icon update
static unsigned long lastWifiUpdate = 0;
const unsigned long wifiUpdateInterval = 5000UL; // Update every 5 seconds
// New: Timer for parcelbox credentials fetch
static unsigned long lastCredentialsCheck = 0;
const unsigned long credentialsInterval = 1800000UL; // 30 minutes
// Debounce for touch events
static unsigned long lastEventTime = 0;
const unsigned long debounceDelay = 200;
// New: Timer for background image update (e.g., every hour)
static unsigned long lastBackgroundUpdate = 0;
const unsigned long backgroundUpdateInterval = 3600000UL; // 1 hour
static unsigned long lastWeatherLocationCheck = 0;
const unsigned long weatherLocationInterval = 1800000UL; // 30 minutes
////////////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////////////////////
// Weather data
struct CurrentWeather {
  float temperature_2m;
  float relative_humidity_2m;
  float apparent_temperature;
  float precipitation;
  int weather_code;
  float wind_speed_10m;
  int wind_direction_10m;
  float surface_pressure;  // NEW: Atmospheric pressure in hPa
};
CurrentWeather current_weather;
struct DailyWeather {
  int weather_code;
  float temp_max;
  float apparent_max;
  float temp_min;  // NEW: Added for night/minimum temperature
  float precip_sum;
  float wind_max;
  float humidity_mean;
};
DailyWeather forecast[14]; // Increased from 7 to 14 to store subsequent 14 days
// New: Air Quality data
int current_aqi = 0; // Current European AQI
// Helper function to print memory usage
void printMemoryUsage() {
  if (debug == 1) {
    Serial.printf("[DEBUG] Free heap: %d bytes, Free PSRAM: %d bytes\n",
                  heap_caps_get_free_size(MALLOC_CAP_8BIT),
                  heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  }
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
// New: Function to get AQI description and color
String getAqiDescription(int aqi) {
  if (aqi <= 20) return "Very Good";
  else if (aqi <= 40) return "Good";
  else if (aqi <= 60) return "Moderate";
  else if (aqi <= 80) return "Poor";
  else if (aqi <= 100) return "Very Poor";
  else return "Extremely Poor";
}
lv_color_t getAqiColor(int aqi) {
  if (aqi <= 20) return lv_color_hex(0x00FF00); // Green
  else if (aqi <= 40) return lv_color_hex(0xFFFF00); // Yellow
  else if (aqi <= 60) return lv_color_hex(0xFFA500); // Orange
  else if (aqi <= 80) return lv_color_hex(0xFF0000); // Red
  else if (aqi <= 100) return lv_color_hex(0x800000); // Maroon
  else return lv_color_hex(0x4B0082); // Purple
}
// Declarations for weather images (assuming conversions are included similarly to the example)
extern const lv_image_dsc_t Clear_sky;
extern const lv_image_dsc_t Mainly_clear;
extern const lv_image_dsc_t Partly_cloudy;
extern const lv_image_dsc_t Overcast;
extern const lv_image_dsc_t Fog;
extern const lv_image_dsc_t Depositing_rime_fog;
extern const lv_image_dsc_t Light_drizzle;
extern const lv_image_dsc_t Moderate_drizzle;
extern const lv_image_dsc_t Dense_drizzle;
extern const lv_image_dsc_t Slight_rain;
extern const lv_image_dsc_t Moderate_rain;
extern const lv_image_dsc_t Heavy_rain;
extern const lv_image_dsc_t Slight_snow_fall;
extern const lv_image_dsc_t Moderate_snow_fall;
extern const lv_image_dsc_t Heavy_snow_fall;
extern const lv_image_dsc_t Slight_rain_showers;
extern const lv_image_dsc_t Moderate_rain_showers;
extern const lv_image_dsc_t Violent_rain_showers;
extern const lv_image_dsc_t Thunderstorm;
extern const lv_image_dsc_t Thunderstorm_with_slight_hail;
extern const lv_image_dsc_t Thunderstorm_with_heavy_hail;
extern const lv_image_dsc_t Unknown;
extern const lv_image_dsc_t crontab;
extern const lv_image_dsc_t loading;
extern const lv_image_dsc_t qr;
// New: Declaration for the box image (convert your image to C array and include the file)
extern const lv_image_dsc_t box;
// New: Declarations for WiFi signal strength icons (convert your images to C arrays and include the files)
extern const lv_image_dsc_t full; // for max signal
extern const lv_image_dsc_t medium; // for medium signal
extern const lv_image_dsc_t average; // for average signal
extern const lv_image_dsc_t low; // for low signal
extern const lv_image_dsc_t none; // for no signal
const lv_image_dsc_t* getWeatherImage(int code) {
  switch (code) {
    case 0: return &Clear_sky;
    case 1: return &Mainly_clear;
    case 2: return &Partly_cloudy;
    case 3: return &Overcast;
    case 45: return &Fog;
    case 48: return &Depositing_rime_fog;
    case 51: return &Light_drizzle;
    case 53: return &Moderate_drizzle;
    case 55: return &Dense_drizzle;
    case 61: return &Slight_rain;
    case 63: return &Moderate_rain;
    case 65: return &Heavy_rain;
    case 71: return &Slight_snow_fall;
    case 73: return &Moderate_snow_fall;
    case 75: return &Heavy_snow_fall;
    case 80: return &Slight_rain_showers;
    case 81: return &Moderate_rain_showers;
    case 82: return &Violent_rain_showers;
    case 95: return &Thunderstorm;
    case 96: return &Thunderstorm_with_slight_hail;
    case 99: return &Thunderstorm_with_heavy_hail;
    default: return &Unknown;
  }
}
// Replace the existing getWifiImage() function with this updated version
const lv_image_dsc_t* getWifiImage() {
  if (WiFi.status() != WL_CONNECTED) {
    return &none;
  }
  int rssi = WiFi.RSSI();
  if (rssi >= -50) {
    return &full;
  } else if (rssi >= -60) {
    return &medium;
  } else if (rssi >= -70) {
    return &average;
  } else if (rssi >= -80) {
    return &low;
  } else {
    return &none;
  }
}
// URL encoding function
String URLEncode(const String& str) {
  String encoded = "";
  for (size_t i = 0; i < str.length(); i++) {
    char c = str[i];
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      char hex[4];
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded += hex;
    }
  }
  return encoded;
}
void rearrange_calendar_parts(lv_obj_t *cal) {
  lv_obj_t *header = NULL;
  lv_obj_t *btnm = lv_calendar_get_btnmatrix(cal);
  lv_obj_t *days[7] = {NULL};
  int day_idx = 7;
  int child_cnt = lv_obj_get_child_cnt(cal);
  for (int i = 0; i < child_cnt; i++) {
    lv_obj_t *child = lv_obj_get_child(cal, i);
    if (child == btnm) continue;
    if (lv_obj_check_type(child, &lv_label_class)) {
      if (day_idx < 7) days[day_idx++] = child;
    } else {
      header = child;
    }
  }
  if (header) {
    lv_obj_add_flag(header, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_height(header, 0);
  }
  if (btnm) {
    lv_obj_align(btnm, LV_ALIGN_TOP_MID, 0, 0);
    lv_coord_t cal_h = lv_obj_get_height(cal);
    lv_coord_t day_h = (days[0] ? lv_obj_get_height(days[0]) : 20);
    lv_obj_set_height(btnm, cal_h - day_h);
  }
  lv_coord_t w = lv_obj_get_width(cal);
  lv_coord_t cell_w = w / 7;
  for (int i = 0; i < 7; i++) {
    if (days[i]) {
      lv_obj_align(days[i], LV_ALIGN_BOTTOM_MID, cell_w * i + cell_w / 2, 0);
    }
  }
}
// Forward declarations (add the following line)
void addEvent(JsonObject eventObj);
// Modified fetchEvents function
void fetchEvents() {
  if (debug == 1) Serial.println("[APP] Starting event fetch...");
  printMemoryUsage();
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected");
    return;
  }
  if (debug == 1) Serial.println("[APP] Starting HTTP request...");
  HTTPClient http;
  String url = "https://crontech.uk/api.php?code=" + apiCode;
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    if (debug == 1) Serial.println("[APP] Response: " + payload);
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      if (debug == 1) Serial.println("[APP] JSON parsing failed: " + String(error.c_str()));
      return;
    }
    JsonArray eventArray = doc["events"];
    numEvents = 0;
    numEventDates = 0;
    if (eventArray.isNull()) {
      if (debug == 1) Serial.println("[APP] No events found in response");
      return;
    }
    if (debug == 1) Serial.println("[APP] Processing " + String(eventArray.size()) + " events");
    for (JsonObject eventObj : eventArray) {
      if (numEvents >= 4000) {
        if (debug == 1) Serial.println("[APP] Event limit reached (4000)");
        break;
      }
      addEvent(eventObj);
    }
    if (debug == 1) Serial.println("[APP] Fetched " + String(numEvents) + " events, " + String(numEventDates) + " highlight dates");
  } else {
    if (debug == 1) Serial.println("[APP] HTTP request failed: " + String(httpCode));
    return;
  }
  http.end();
  printMemoryUsage();
}
// New function to fetch notifications and display image if new event
void fetchNotifications() {
  if (debug == 1) Serial.println("[APP] Fetching notifications from https://cloudapps.zapto.org/spb/api.php...");
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected, skipping notification fetch");
    return;
  }
  HTTPClient http;
  String url = "https://cloudapps.zapto.org/spb/api.php?username=" + username + "&device_id=" + device_id;
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    if (debug == 1) Serial.println("[APP] Notification response: " + payload);
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      if (debug == 1) Serial.println("[APP] Notification JSON parsing failed: " + String(error.c_str()));
      http.end();
      return;
    }
    String title = doc["title"].as<String>();
    String text = doc["text"].as<String>();
    String cookie = doc["cookie"].as<String>(); // Note: In modified PHP, it's "device_id" now, but assuming "cookie" for compatibility
    String this_notification = title + "_" + text;
    // Check if valid user and has notification text (indicating new event)
    if (cookie != "unknown" && !text.isEmpty() && this_notification != last_ignored_notification && title != "No Data") {
      if (debug == 1) Serial.println("[APP] New notification event detected, displaying image");
      current_notification_text = this_notification;
      if (!notification_img) {
        notification_img = lv_img_create(lv_scr_act());
        lv_img_set_src(notification_img, &box);
        lv_img_set_zoom(notification_img, 102);
        lv_obj_align(notification_img, LV_ALIGN_TOP_RIGHT, -110, -13); // Top-right with padding
        lv_obj_add_flag(notification_img, LV_OBJ_FLAG_CLICKABLE); // Make the image clickable
        lv_obj_add_event_cb(notification_img, notification_click_cb, LV_EVENT_CLICKED, NULL);
        lv_obj_clear_flag(notification_img, LV_OBJ_FLAG_HIDDEN);
        notification_visible = true;
        notification_timer = lv_timer_create(notification_toggle_cb, 2000, NULL);
      }
    } else {
      if (debug == 1) Serial.println("[APP] No new notification event, hiding image");
      if (notification_img) {
        lv_obj_del(notification_img);
        notification_img = nullptr;
      }
    }
  } else {
    if (debug == 1) Serial.println("[APP] Notification HTTP request failed: " + String(httpCode));
  }
  http.end();
}
void notification_click_cb(lv_event_t *e) {
  if (notification_img) {
    if (notification_timer) lv_timer_del(notification_timer);
    notification_timer = NULL;
    lv_obj_del(notification_img);
    notification_img = nullptr;
  }
  last_ignored_notification = current_notification_text;
  // Mark as read
  HTTPClient http;
  String url = "https://cloudapps.zapto.org/spb/api.php?device_id=" + device_id + "&username=" + username + "&id_read_status=1";
  if (debug == 1) Serial.println("[DEBUG] Starting mark as read process");
  if (debug == 1) Serial.println("[DEBUG] Constructed URL: " + url);
  http.begin(url);
  if (debug == 1) Serial.println("[DEBUG] HTTP client initialized with URL");
  int httpCode = http.GET();
  if (debug == 1) Serial.println("[DEBUG] HTTP GET request sent, response code: " + String(httpCode));
  if (httpCode == HTTP_CODE_OK) {
    if (debug == 1) Serial.println("[APP] Marked as read successfully");
  } else {
    if (debug == 1) Serial.println("[APP] Failed to mark as read: " + String(httpCode));
  }
  http.end();
  if (debug == 1) Serial.println("[DEBUG] HTTP client ended");
}
void blink_animation_cb(void * var, int32_t v) {
  lv_obj_set_style_img_opa((lv_obj_t *)var, v, 0);
}
void notification_toggle_cb(lv_timer_t *timer) {
  if (notification_visible) {
    lv_obj_add_flag(notification_img, LV_OBJ_FLAG_HIDDEN);
    notification_visible = false;
    lv_timer_set_period(timer, 1000);
  } else {
    lv_obj_clear_flag(notification_img, LV_OBJ_FLAG_HIDDEN);
    notification_visible = true;
    lv_timer_set_period(timer, 2000);
  }
}
void fetchWeather() {
  if (debug == 1) Serial.println("[APP] Fetching weather...");
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected");
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
        if (debug == 1) Serial.println("[APP] Geocoded location to lat: " + lat + ", lon: " + lon);
      }
    }
    httpGeo.end();
  }
  if (lat.isEmpty() || lon.isEmpty()) {
    if (debug == 1) Serial.println("[APP] Failed to geocode location");
    return;
  }
  
  
// Get current weather and minutely_15 forecast from Open-Meteo (UPDATED: Added temperature_2m_min and surface_pressure)
HTTPClient http;
String url = "https://api.open-meteo.com/v1/forecast?latitude=" + lat + "&longitude=" + lon + 
             "&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_direction_10m,surface_pressure" +  // UPDATED: Added surface_pressure
             "&minutely_15=temperature_2m,relative_humidity_2m,weather_code,precipitation,wind_speed_10m" +  // NEW: 15-min data for ~half-hour forecast
             "&daily=weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,precipitation_sum,wind_speed_10m_max,relative_humidity_2m_mean" +  // UPDATED: Added temperature_2m_min
             "&forecast_minutely_15=96" +  // NEW: 96 timesteps = 24 hours of 15-min data
             "&timezone=auto&forecast_days=14";  // UPDATED: forecast_days=14 for consistency
http.begin(url);
int httpCode = http.GET();
if (httpCode == HTTP_CODE_OK) {
  String payload = http.getString();
  JsonDocument doc;
  DeserializationError error = deserializeJson(doc, payload);
  if (error) {
    if (debug == 1) Serial.println("[APP] Weather JSON parsing failed: " + String(error.c_str()));
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
  current_weather.surface_pressure = current["surface_pressure"].as<float>();  // NEW: Parse surface pressure
  if (debug == 1) Serial.println("[APP] Fetched current weather including pressure: " + String(current_weather.surface_pressure) + " hPa");
    
	
	
    // NEW: Parse minutely_15 for next ~30 min weather code
    JsonObject minutely15 = doc["minutely_15"];
    if (!minutely15.isNull()) {
      JsonArray m_time = minutely15["time"];
      JsonArray m_code = minutely15["weather_code"];
      time_t now_t;
      time(&now_t);
      struct tm target_tm = *localtime(&now_t);
      target_tm.tm_min += 30;
      target_tm.tm_sec = 0;
      time_t target_time = mktime(&target_tm);  // Normalize to next half-hour
      int next_index = -1;
      time_t closest_time = 0;
      for (size_t i = 0; i < m_time.size(); ++i) {
        const char* time_str = m_time[i].as<const char*>();
        struct tm parse_tm = {0};
        if (strptime(time_str, "%Y-%m-%dT%H:%M", &parse_tm) != NULL) {
          parse_tm.tm_sec = 0;
          time_t parse_time = mktime(&parse_tm);
          if (parse_time >= target_time && (next_index == -1 || parse_time < closest_time)) {
            next_index = i;
            closest_time = parse_time;
          }
        }
      }
      if (next_index != -1) {
        next_weather.weather_code = m_code[next_index].as<int>();
        if (debug == 1) Serial.println("[APP] Next ~30 min weather code: " + String(next_weather.weather_code));
      } else {
        next_weather.weather_code = current_weather.weather_code;  // Fallback
      }
    } else {
      next_weather.weather_code = current_weather.weather_code;  // Fallback if no minutely data
    }
    
    JsonObject daily = doc["daily"];
    JsonArray weather_codes = daily["weather_code"];
    JsonArray temp_max = daily["temperature_2m_max"];
    JsonArray temp_min = daily["temperature_2m_min"];  // NEW: Parse min temps
    JsonArray apparent_max = daily["apparent_temperature_max"];
    JsonArray precip_sum = daily["precipitation_sum"];
    JsonArray wind_max = daily["wind_speed_10m_max"];
    JsonArray humidity_mean = daily["relative_humidity_2m_mean"];
    for (int i = 0; i < 14; i++) { // Increased from 7 to 14 to process and store all requested days
      forecast[i].weather_code = weather_codes[i].as<int>();
      forecast[i].temp_max = temp_max[i].as<float>();
      forecast[i].temp_min = temp_min[i].as<float>();  // NEW: Store min temp
      forecast[i].apparent_max = apparent_max[i].as<float>();
      forecast[i].precip_sum = precip_sum[i].as<float>();
      forecast[i].wind_max = wind_max[i].as<float>();
      forecast[i].humidity_mean = humidity_mean[i].as<float>();
    }
    if (debug == 1) Serial.println("[APP] Fetched 14-day forecast with min/max temps"); // Updated log message
  } else {
    if (debug == 1) Serial.println("[APP] Weather request failed: " + String(httpCode));
  }
  http.end();
  // Fetch air quality (unchanged)
  HTTPClient http_aq;
  String aq_url = "https://air-quality-api.open-meteo.com/v1/air-quality?latitude=" + lat + "&longitude=" + lon + "&current=european_aqi&timezone=auto";
  http_aq.begin(aq_url);
  int aq_httpCode = http_aq.GET();
  if (aq_httpCode == HTTP_CODE_OK) {
    String aq_payload = http_aq.getString();
    JsonDocument aq_doc;
    DeserializationError aq_error = deserializeJson(aq_doc, aq_payload);
    if (!aq_error) {
      current_aqi = aq_doc["current"]["european_aqi"].as<int>();
      if (debug == 1) Serial.println("[APP] Fetched current AQI: " + String(current_aqi));
    } else {
      if (debug == 1) Serial.println("[APP] Air quality JSON parsing failed: " + String(aq_error.c_str()));
    }
  } else {
    if (debug == 1) Serial.println("[APP] Air quality request failed: " + String(aq_httpCode));
  }
  http_aq.end();
}

void updateWeatherDisplay() {
  if (debug == 1) Serial.println("[APP] Updating weather display...");
  if (!weatherContainer) {
    weatherContainer = lv_obj_create(lv_scr_act());
    lv_obj_set_size(weatherContainer, 400, 235);
    lv_obj_align(weatherContainer, LV_ALIGN_BOTTOM_RIGHT, -10, -13);
    lv_obj_set_style_bg_opa(weatherContainer, LV_OPA_90, 0);
    lv_obj_set_style_border_width(weatherContainer, 0, 0);
    lv_obj_set_style_radius(weatherContainer, 10, 0);
    lv_obj_set_style_shadow_color(weatherContainer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_shadow_width(weatherContainer, 20, 0);
    lv_obj_set_style_shadow_opa(weatherContainer, LV_OPA_10, 0);
    if (debug == 1) Serial.println("[APP] Created weatherContainer");
  } else {
    lv_obj_clean(weatherContainer);
    lv_obj_invalidate(weatherContainer);
  }
  lv_obj_set_scrollbar_mode(weatherContainer, LV_SCROLLBAR_MODE_OFF);
  // Calculate grayscale based on darkness
  uint8_t gray = 255 - (g_ui_darkness * 255 / 100);
  lv_color_t weather_bg_color = lv_color_make(gray, gray, gray);
  lv_obj_set_style_bg_color(weatherContainer, weather_bg_color, 0);
  lv_color_t main_text_color = (g_ui_darkness > 50) ? lv_color_hex(0xFFFFFF) : lv_color_hex(0x636e72);
  lv_color_t temp_text_color = (g_ui_darkness > 50) ? lv_color_hex(0xFFFFFF) : lv_color_hex(0x2d3436);
  // Weather image (CHANGED: Use next_weather.weather_code for next half-hour condition)
  lv_obj_t *weather_img = lv_img_create(weatherContainer);
  lv_img_set_src(weather_img, getWeatherImage(next_weather.weather_code));
  lv_obj_align(weather_img, LV_ALIGN_CENTER, 0, -80);
  // Temperature label (UNCHANGED: Current temperature)
  lv_obj_t *temp_label = lv_label_create(weatherContainer);
  lv_label_set_text(temp_label, (String(current_weather.temperature_2m + temp_adjust, 1) + "°C").c_str());
  lv_obj_set_style_text_font(temp_label, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(temp_label, temp_text_color, 0);
  lv_obj_align(temp_label, LV_ALIGN_CENTER, 0, -25);
  // AQI label on the right side of the image and temperature (UNCHANGED)
  lv_obj_t *aqi_label_local = lv_label_create(weatherContainer);
  lv_label_set_text(aqi_label_local, ("AQI: " + getAqiDescription(current_aqi)).c_str());
  lv_obj_set_style_text_font(aqi_label_local, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(aqi_label_local, getAqiColor(current_aqi), 0);
  lv_obj_align(aqi_label_local, LV_ALIGN_CENTER, 120, -85); // Moved to right side, symmetric with description
  // Description (CHANGED: Use next_weather.weather_code for next half-hour condition)
  String desc = getWeatherDescription(next_weather.weather_code);
  lv_obj_t *desc_label = lv_label_create(weatherContainer);
  lv_label_set_text(desc_label, desc.c_str());
  lv_obj_set_style_text_font(desc_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(desc_label, main_text_color, 0);
  lv_obj_align(desc_label, LV_ALIGN_CENTER, -145, -60);
  // Header container for location (UNCHANGED)
  lv_obj_t *header_cont = lv_obj_create(weatherContainer);
  lv_obj_set_size(header_cont, LV_SIZE_CONTENT, LV_SIZE_CONTENT);
  lv_obj_set_flex_flow(header_cont, LV_FLEX_FLOW_ROW);
  lv_obj_align(header_cont, LV_ALIGN_CENTER, -160, -85);
  lv_obj_set_style_bg_opa(header_cont, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(header_cont, 0, 0);
  lv_obj_set_style_pad_all(header_cont, 0, 0);
  lv_obj_set_style_pad_column(header_cont, 3, 0);
  // Location
  lv_obj_t *loc_label = lv_label_create(header_cont);
  lv_label_set_text(loc_label, location.c_str());
  lv_obj_set_style_text_color(loc_label, main_text_color, 0);
  lv_obj_set_style_text_font(loc_label, &lv_font_montserrat_14, 0);
  lv_obj_align(loc_label, LV_ALIGN_CENTER, 0, -10);
  // Buttons container (UNCHANGED: Uses current_weather values)
  lv_obj_t *buttons_cont = lv_obj_create(weatherContainer);
  lv_obj_set_size(buttons_cont, 380, 55);
  lv_obj_align(buttons_cont, LV_ALIGN_CENTER, 0, 30);
  lv_obj_set_flex_flow(buttons_cont, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(buttons_cont, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_set_style_bg_opa(buttons_cont, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(buttons_cont, 0, 0);
  lv_obj_set_style_pad_all(buttons_cont, 0, 0);
  lv_obj_set_scrollbar_mode(buttons_cont, LV_SCROLLBAR_MODE_OFF);
  // Humidity (UPDATED: Dark text color)
  lv_obj_t *hum_cont = lv_obj_create(buttons_cont);
  lv_obj_set_size(hum_cont, 108, 55);
  lv_obj_set_style_bg_color(hum_cont, lv_color_hex(0x00b894), 0);
  lv_obj_set_style_bg_grad_color(hum_cont, lv_color_hex(0x00a085), 0);
  lv_obj_set_style_bg_grad_dir(hum_cont, LV_GRAD_DIR_HOR, 0);
  lv_obj_set_style_radius(hum_cont, 10, 0);
  lv_obj_t *hum_val = lv_label_create(hum_cont);
  lv_label_set_text(hum_val, ("Hum\n" + String((int)current_weather.relative_humidity_2m) + "%").c_str());
  lv_obj_set_style_text_font(hum_val, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(hum_val, lv_color_hex(0x000000), 0);  // UPDATED: Dark color
  lv_obj_set_scrollbar_mode(hum_val, LV_SCROLLBAR_MODE_OFF);
  lv_obj_center(hum_val);
  // Wind (UPDATED: Dark text color)
  lv_obj_t *wind_cont = lv_obj_create(buttons_cont);
  lv_obj_set_size(wind_cont, 108, 50);
  lv_obj_set_style_bg_color(wind_cont, lv_color_hex(0xfd79a8), 0);
  lv_obj_set_style_bg_grad_color(wind_cont, lv_color_hex(0xe84393), 0);
  lv_obj_set_style_bg_grad_dir(wind_cont, LV_GRAD_DIR_HOR, 0);
  lv_obj_set_style_radius(wind_cont, 10, 0);
  lv_obj_t *wind_val = lv_label_create(wind_cont);
  lv_label_set_text(wind_val, ("Wind\n" + String((int)current_weather.wind_speed_10m) + " km/h").c_str());
  lv_obj_set_style_text_font(wind_val, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(wind_val, lv_color_hex(0x000000), 0);  // UPDATED: Dark color
  lv_obj_set_scrollbar_mode(wind_val, LV_SCROLLBAR_MODE_OFF);
  lv_obj_center(wind_val);
// Pressure (REPLACED: Atmospheric pressure in hPa instead of precipitation)
lv_obj_t *pressure_cont = lv_obj_create(buttons_cont);
lv_obj_set_size(pressure_cont, 108, 50);
lv_obj_set_style_bg_color(pressure_cont, lv_color_hex(0x55a3ff), 0);
lv_obj_set_style_bg_grad_color(pressure_cont, lv_color_hex(0x007acc), 0);
lv_obj_set_style_bg_grad_dir(pressure_cont, LV_GRAD_DIR_HOR, 0);
lv_obj_set_style_radius(pressure_cont, 10, 0);
lv_obj_t *pressure_val = lv_label_create(pressure_cont);
lv_label_set_text(pressure_val, ("Pressure\n" + String((int)current_weather.surface_pressure) + " hPa").c_str());
lv_obj_set_style_text_font(pressure_val, &lv_font_montserrat_14, 0);
lv_obj_set_style_text_color(pressure_val, lv_color_hex(0x000000), 0);  // Dark color
lv_obj_set_scrollbar_mode(pressure_val, LV_SCROLLBAR_MODE_OFF);
lv_obj_center(pressure_val);
  // Forecast container (UPDATED: 7-day daily forecast with max/min temperatures)
  lv_obj_t *forecast_cont = lv_obj_create(weatherContainer);
  lv_obj_set_size(forecast_cont, 350, 80);
  lv_obj_align(forecast_cont, LV_ALIGN_CENTER, 0, 90);
  lv_obj_set_flex_flow(forecast_cont, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(forecast_cont, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_set_style_bg_opa(forecast_cont, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(forecast_cont, 0, 0);
  lv_obj_set_style_pad_all(forecast_cont, 0, 0);
  lv_obj_set_style_pad_column(forecast_cont, 0, 0);
  lv_obj_set_scrollbar_mode(forecast_cont, LV_SCROLLBAR_MODE_OFF);
  struct tm timeinfo;
  getLocalTime(&timeinfo);
  int today_wday = timeinfo.tm_wday;
  const char * day_names[7] = {"Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"};
  for (int i = 0; i < 7; i++) {
    lv_obj_t *day_cont = lv_obj_create(forecast_cont);
    lv_obj_set_size(day_cont, 40, 80);
    lv_obj_set_flex_flow(day_cont, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_flex_align(day_cont, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_set_style_bg_opa(day_cont, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(day_cont, 0, 0);
    lv_obj_set_style_pad_all(day_cont, 0, 0);
    lv_obj_set_style_pad_row(day_cont, -30, 0);
    int wday = (today_wday + i) % 7;
    lv_obj_t *day_label = lv_label_create(day_cont);
    lv_label_set_text(day_label, day_names[wday]);
    lv_obj_set_style_text_font(day_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(day_label, main_text_color, 0);
    lv_obj_t *forecast_img = lv_img_create(day_cont);
    lv_img_set_src(forecast_img, getWeatherImage(forecast[i].weather_code));
    lv_img_set_zoom(forecast_img, 128);
    lv_obj_t *temp_label = lv_label_create(day_cont);
    // UPDATED: Format as "max°/min°" (e.g., "7°/-1°")
    String temp_text = String(forecast[i].temp_max + temp_adjust, 0) + "°/" + String(forecast[i].temp_min + temp_adjust, 0) + "°";
    lv_label_set_text(temp_label, temp_text.c_str());
    lv_obj_set_style_text_font(temp_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(temp_label, temp_text_color, 0);
    lv_obj_set_scrollbar_mode(day_cont, LV_SCROLLBAR_MODE_OFF);
  }
  lv_calendar_set_day_names(calendar, day_names);
}

bool isDateHighlightable(int year, int month, int day) {
  for (int i = 0; i < numEventDates; i++) {
    if (eventDates[i].year == year && eventDates[i].month == month && eventDates[i].day == day) {
      if (debug == 1) Serial.println("[APP] Date " + String(year) + "-" + String(month) + "-" + String(day) + " is highlightable");
      return true;
    }
  }
  if (debug == 1) Serial.println("[APP] Date " + String(year) + "-" + String(month) + "-" + String(day) + " is NOT highlightable");
  return false;
}
// Note: To resolve the display issue with the DS-Digital font, edit DS-Digital.c and change .line_height = 41 to .line_height = 64;
// This addresses a potential bug in the LVGL font converter for v8. Ensure DS-Digital.c is included in your build.

void updateEventDisplay(lv_obj_t *calendar) {
  if (debug == 1) Serial.println("[APP] Updating event display...");
  printMemoryUsage();

  // NEW: Delete timer before cleaning to avoid dangling references
  if (blink_timer) {
    lv_timer_del(blink_timer);
    blink_timer = NULL;
  }

  // Initialize or clean event container
  if (!eventContainer) {
    eventContainer = lv_obj_create(lv_scr_act());
    lv_obj_set_size(eventContainer, 400, 175);
    lv_obj_align(eventContainer, LV_ALIGN_TOP_RIGHT, -10, 50);
    lv_obj_set_style_bg_color(eventContainer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_width(eventContainer, 0, 0);
    lv_obj_set_scrollbar_mode(eventContainer, LV_SCROLLBAR_MODE_OFF);
    if (debug == 1) Serial.println("[APP] Created eventContainer");
  } else {
    lv_obj_clean(eventContainer);
    lv_obj_invalidate(eventContainer);
  }

  // Set highlighted dates on calendar (unchanged)
  if (debug == 1) Serial.println("[APP] Setting highlighted dates...");
  lv_calendar_date_t highlighted_dates[1000];
  int highlight_count = 0;
  for (int i = 0; i < numEventDates && highlight_count < 1000; i++) {
    highlighted_dates[highlight_count].year = eventDates[i].year;
    highlighted_dates[highlight_count].month = eventDates[i].month;
    highlighted_dates[highlight_count].day = eventDates[i].day;
    if (debug == 1) Serial.println("[APP] Highlighting date: " +
                   String(highlighted_dates[highlight_count].year) + "-" +
                   String(highlighted_dates[highlight_count].month) + "-" +
                   String(highlighted_dates[highlight_count].day));
    highlight_count++;
  }
  if (calendar) {
    lv_calendar_set_highlighted_dates(calendar, highlighted_dates, highlight_count);
    if (debug == 1) Serial.println("[APP] Highlighted " + String(highlight_count) + " dates");
    update_today_highlight(calendar);
  } else {
    if (debug == 1) Serial.println("[APP] Error: Calendar object is null in updateEventDisplay");
  }

  int y_offset = -10;
  int total_displayed = 0;
  time_t now;
  time(&now);
  struct tm *nowTm = localtime(&now);
  time_t todayStart = mktime(nowTm);
  todayStart -= todayStart % 86400; // Start of today
  time_t todayEnd = todayStart + 86400 - 1; // End of today

  // Step 1: Display ongoing events (unchanged)
  for (int i = 0; i < numEvents; i++) {
    if (events[i].start_time < todayStart && events[i].end_time > now) {
      // This is an ongoing event
      lv_obj_t *event_cont = lv_obj_create(eventContainer);
      lv_obj_set_size(event_cont, 381, 30); // 50% height
      lv_obj_align(event_cont, LV_ALIGN_TOP_MID, 0, y_offset);
      // Apply gray semi-transparent background
      lv_obj_set_style_bg_color(event_cont, lv_color_hex(0x808080), 0);
      lv_obj_set_style_bg_opa(event_cont, LV_OPA_70, 0); // 50% opacity
      lv_obj_set_style_radius(event_cont, 10, 0);
      lv_obj_set_style_pad_all(event_cont, 5, 0);
      lv_obj_set_user_data(event_cont, (void*)(intptr_t)i);
      lv_obj_add_event_cb(event_cont, show_event_details_cb, LV_EVENT_PRESSED, NULL);

      // Title with remaining days (faded yellow)
      String summary = events[i].summary;
      if (summary.length() > 55) summary = summary.substring(10, 55) + "...";
      // Calculate remaining days
      int remaining_days = ceil((events[i].end_time - now) / 86400.0); // Round up to include partial days
      if (remaining_days < 0) remaining_days = 0; // Ensure non-negative
      summary += " (" + String(remaining_days) + " days)";
      lv_obj_t *title_label = lv_label_create(event_cont);
      lv_label_set_text(title_label, summary.c_str());
      lv_obj_set_style_text_color(title_label, lv_color_hex(0xFFFF99), 0); // Faded yellow
      lv_obj_set_style_text_font(title_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_align(title_label, LV_TEXT_ALIGN_LEFT, 0);
      lv_label_set_long_mode(title_label, LV_LABEL_LONG_WRAP);
      lv_obj_set_width(title_label, lv_pct(100));
      lv_obj_align(title_label, LV_ALIGN_TOP_LEFT, -10, 0);

      y_offset += 35; // Adjusted for smaller height
      total_displayed++;
    }
  }

  // Step 2: Display today's events (unchanged)
  for (int i = 0; i < numEvents; i++) {
    if (events[i].isToday) {
      lv_obj_t *event_cont = lv_obj_create(eventContainer);
      lv_obj_set_size(event_cont, 361, 65);
      lv_obj_align(event_cont, LV_ALIGN_TOP_MID, 0, y_offset);
      lv_obj_set_style_bg_color(event_cont, lv_color_hex(0xfd79a8), 0);
      lv_obj_set_style_bg_grad_color(event_cont, lv_color_hex(0xe84393), 0);
      lv_obj_set_style_bg_grad_dir(event_cont, LV_GRAD_DIR_HOR, 0);
      lv_obj_set_style_radius(event_cont, 10, 0);
      lv_obj_set_style_pad_all(event_cont, 5, 0);
      lv_obj_set_user_data(event_cont, (void*)(intptr_t)i);
      lv_obj_add_event_cb(event_cont, show_event_details_cb, LV_EVENT_PRESSED, NULL);

      String summary = events[i].summary;
      if (summary.length() > 55) summary = summary.substring(0, 55) + "...";
      String description = events[i].description;
      if (description.length() > 55) description = description.substring(0, 55) + "...";
      String start_time_str = events[i].start.substring(11, 16);
      String end_time_str = events[i].end.substring(11, 16);

      // Title label (blue)
      lv_obj_t *title_label = lv_label_create(event_cont);
      lv_label_set_text(title_label, summary.c_str());
      lv_obj_set_style_text_color(title_label, lv_color_hex(0x0000FF), 0);
      lv_obj_set_style_text_font(title_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_align(title_label, LV_TEXT_ALIGN_LEFT, 0);
      lv_label_set_long_mode(title_label, LV_LABEL_LONG_WRAP);
      lv_obj_set_width(title_label, lv_pct(100));
      lv_obj_align(title_label, LV_ALIGN_TOP_LEFT, 0, 0);

      // Time row
      lv_obj_t *time_base = nullptr;
      if (events[i].isAllDay) {
        lv_obj_t *all_day_label = lv_label_create(event_cont);
        lv_label_set_text(all_day_label, "All day");
        lv_obj_set_style_text_color(all_day_label, lv_color_hex(0x800000), 0);
        lv_obj_set_style_text_font(all_day_label, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_align(all_day_label, LV_TEXT_ALIGN_LEFT, 0);
        lv_obj_align_to(all_day_label, title_label, LV_ALIGN_OUT_BOTTOM_LEFT, 0, 0);
        time_base = all_day_label;
      } else {
        lv_obj_t *from_label = lv_label_create(event_cont);
        lv_label_set_text(from_label, "from ");
        lv_obj_set_style_text_color(from_label, lv_color_hex(0x800000), 0);
        lv_obj_set_style_text_font(from_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(from_label, title_label, LV_ALIGN_OUT_BOTTOM_LEFT, 0, 0);
        lv_obj_t *start_time_label = lv_label_create(event_cont);
        lv_label_set_text(start_time_label, start_time_str.c_str());
        lv_obj_set_style_text_color(start_time_label, lv_color_hex(0x800000), 0);
        lv_obj_set_style_text_font(start_time_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(start_time_label, from_label, LV_ALIGN_OUT_RIGHT_MID, 0, 0);
        lv_obj_t *to_label = lv_label_create(event_cont);
        lv_label_set_text(to_label, " to ");
        lv_obj_set_style_text_color(to_label, lv_color_hex(0x800000), 0);
        lv_obj_set_style_text_font(to_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(to_label, start_time_label, LV_ALIGN_OUT_RIGHT_MID, 0, 0);
        lv_obj_t *end_time_label = lv_label_create(event_cont);
        lv_label_set_text(end_time_label, end_time_str.c_str());
        lv_obj_set_style_text_color(end_time_label, lv_color_hex(0x800000), 0);
        lv_obj_set_style_text_font(end_time_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(end_time_label, to_label, LV_ALIGN_OUT_RIGHT_MID, 0, 0);
        time_base = from_label;
      }

      // Description (if present)
      if (description.length() > 0) {
        lv_obj_t *desc_label = lv_label_create(event_cont);
        lv_label_set_text(desc_label, description.c_str());
        lv_obj_set_style_text_color(desc_label, lv_color_hex(0x2A2A2A), 0);
        lv_obj_set_style_text_font(desc_label, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_align(desc_label, LV_TEXT_ALIGN_LEFT, 0);
        lv_label_set_long_mode(desc_label, LV_LABEL_LONG_WRAP);
        lv_obj_set_width(desc_label, lv_pct(100));
        lv_obj_align_to(desc_label, time_base, LV_ALIGN_OUT_BOTTOM_LEFT, 0, 0);
      }

      y_offset += 70; // Adjusted for height
      total_displayed++;
    }
  }


  // Step 3: Display upcoming events
  int upcoming_count = 0;
  for (int i = 0; i < numEvents; i++) {
    if (!events[i].isToday && events[i].start_time >= todayStart) {
      upcoming_count++;
    }
  }

  if (upcoming_count > 0) {
    // Upcoming label
    lv_obj_t *upcoming_label = lv_label_create(eventContainer);
    lv_label_set_text(upcoming_label, "Due in more than 3 days");
    lv_obj_set_style_text_font(upcoming_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(upcoming_label, lv_color_hex(0xCAE4CA), 0);
    lv_obj_align(upcoming_label, LV_ALIGN_TOP_LEFT, 10, y_offset);
    y_offset += 25;

    // Display upcoming events (similar to today's, but with date)
    for (int i = 0; i < numEvents; i++) {
      if (!events[i].isToday && events[i].start_time >= todayStart) {
        lv_obj_t *event_cont = lv_obj_create(eventContainer);
        lv_obj_set_size(event_cont, 361, 65);
        lv_obj_align(event_cont, LV_ALIGN_TOP_MID, 0, y_offset);
        lv_obj_set_style_bg_color(event_cont, lv_color_hex(0x74b9ff), 0); // Light blue for upcoming
        lv_obj_set_style_bg_grad_color(event_cont, lv_color_hex(0x0984e3), 0);
        lv_obj_set_style_bg_grad_dir(event_cont, LV_GRAD_DIR_HOR, 0);
        lv_obj_set_style_radius(event_cont, 10, 0);
        lv_obj_set_style_pad_all(event_cont, 5, 0);
        lv_obj_set_user_data(event_cont, (void*)(intptr_t)i);
        lv_obj_add_event_cb(event_cont, show_event_details_cb, LV_EVENT_PRESSED, NULL);

        String summary = events[i].summary;
        if (summary.length() > 55) summary = summary.substring(0, 55) + "...";
        String start_date_str = events[i].start.substring(0, 10); // YYYY-MM-DD

        // Title label (blue)
        lv_obj_t *title_label = lv_label_create(event_cont);
        lv_label_set_text(title_label, summary.c_str());
        lv_obj_set_style_text_color(title_label, lv_color_hex(0x0000FF), 0);
        lv_obj_set_style_text_font(title_label, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_align(title_label, LV_TEXT_ALIGN_LEFT, 0);
        lv_label_set_long_mode(title_label, LV_LABEL_LONG_WRAP);
        lv_obj_set_width(title_label, lv_pct(100));
        lv_obj_align(title_label, LV_ALIGN_TOP_LEFT, 0, 0);

        // Date row
        lv_obj_t *date_label = lv_label_create(event_cont);
        lv_label_set_text(date_label, ("On " + start_date_str).c_str());
        lv_obj_set_style_text_color(date_label, lv_color_hex(0x800000), 0);
        lv_obj_set_style_text_font(date_label, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_align(date_label, LV_TEXT_ALIGN_LEFT, 0);
        lv_obj_align_to(date_label, title_label, LV_ALIGN_OUT_BOTTOM_LEFT, 0, 0);

        y_offset += 70;
        total_displayed++;
      }
    }
  }


  // NEW: Handle timer deletion for events case (safe, though already deleted earlier)
  if (total_displayed > 0) {
    if (blink_timer) {
      lv_timer_del(blink_timer);
      blink_timer = NULL;
    }
  }

// Display large time if no events displayed (MODIFIED for dynamic alignment)
if (total_displayed == 0) {
  struct tm *timeinfo = localtime(&now);
  char hours_buf[3];
  strftime(hours_buf, sizeof(hours_buf), "%H", timeinfo);
  char mins_buf[3];
  strftime(mins_buf, sizeof(mins_buf), "%M", timeinfo);

  // Hours label
  lv_obj_t *large_time_hours = lv_label_create(eventContainer);
  lv_label_set_text(large_time_hours, hours_buf);
  lv_obj_set_style_text_font(large_time_hours, &technology_98, 0);
  lv_obj_set_style_text_color(large_time_hours, lv_color_hex(0xFF0000), 0);
  lv_obj_set_style_text_align(large_time_hours, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(large_time_hours, LV_LABEL_LONG_CLIP);

  // Colon label
  lv_obj_t *large_time_colon = lv_label_create(eventContainer);
  lv_label_set_text(large_time_colon, ":");
  lv_obj_set_style_text_font(large_time_colon, &technology_98, 0);
  lv_obj_set_style_text_color(large_time_colon, lv_color_hex(0xFF0000), 0);
  lv_obj_set_style_text_align(large_time_colon, LV_TEXT_ALIGN_CENTER, 0);
  lv_obj_set_style_text_opa(large_time_colon, LV_OPA_COVER, 0);  // Start visible
  lv_label_set_long_mode(large_time_colon, LV_LABEL_LONG_CLIP);

  // Minutes label
  lv_obj_t *large_time_minutes = lv_label_create(eventContainer);
  lv_label_set_text(large_time_minutes, mins_buf);
  lv_obj_set_style_text_font(large_time_minutes, &technology_98, 0);
  lv_obj_set_style_text_color(large_time_minutes, lv_color_hex(0xFF0000), 0);
  lv_obj_set_style_text_align(large_time_minutes, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(large_time_minutes, LV_LABEL_LONG_CLIP);

// Dynamically calculate alignments based on text widths
lv_point_t hours_size, colon_size, minutes_size;
lv_text_get_size(&hours_size, hours_buf, &technology_98, 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);
lv_text_get_size(&colon_size, ":", &technology_98, 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);
lv_text_get_size(&minutes_size, mins_buf, &technology_98, 0, 0, LV_COORD_MAX, LV_TEXT_FLAG_NONE);

lv_coord_t total_width = hours_size.x + colon_size.x + minutes_size.x;
lv_coord_t half_width = total_width / 2;

lv_coord_t hours_x = -half_width + (hours_size.x / 2);
lv_coord_t colon_x = hours_x + hours_size.x + (colon_size.x / 2) - 25;  // Reduced space by 15px
lv_coord_t mins_x = colon_x + colon_size.x + (minutes_size.x / 2)-2;

lv_obj_align(large_time_hours, LV_ALIGN_CENTER, hours_x, 0);
lv_obj_align(large_time_colon, LV_ALIGN_CENTER, colon_x, 0);
lv_obj_align(large_time_minutes, LV_ALIGN_CENTER, mins_x, 0);

// Force layout update to ensure immediate rendering
lv_obj_update_layout(eventContainer);

  // "No events" label (unchanged, but adjust y_offset if needed for visibility)
  lv_obj_t *noEventsLabel = lv_label_create(eventContainer);
  lv_label_set_text(noEventsLabel, "No events");
  lv_obj_set_style_text_font(noEventsLabel, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(noEventsLabel, lv_color_hex(0xFFFFFF), 0);
  lv_obj_align(noEventsLabel, LV_ALIGN_TOP_LEFT, 10, y_offset + 20);  // Minor adjustment: +20 to avoid overlap with time if y_offset=-10

  // Create/start blink and time-update timer (pass colon as user data)
  blink_timer = lv_timer_create(blink_time_update_cb, 1000, NULL);
  lv_timer_set_user_data(blink_timer, large_time_colon);

  if (debug == 1) {
    Serial.printf("[APP] No events: Dynamic offsets - hours_x=%d, colon_x=%d, mins_x=%d (total_width=%d)\n",
                  hours_x, colon_x, mins_x, total_width);
    Serial.println("[APP] No events: Displaying large red time " + String(hours_buf) + ":" + String(mins_buf) + " with blinking colon");
  }
}

}

void wifi_connect_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] WiFi connect button clicked");
  lv_obj_t *dropdown = (lv_obj_t*)lv_event_get_user_data(e);
  lv_obj_t *password_ta = lv_obj_get_child(wifi_setup_screen, 1);
  char selected_ssid[32];
  lv_dropdown_get_selected_str(dropdown, selected_ssid, sizeof(selected_ssid));
  String ssid_str = String(selected_ssid);
  String password_str = String(lv_textarea_get_text(password_ta));
  if (debug == 1) Serial.println("[APP] Connecting to WiFi: " + ssid_str);
  WiFi.begin(ssid_str.c_str(), password_str.c_str());
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    if (debug == 1) Serial.print(".");
    attempts++;
  }
  if (WiFi.status() == WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi connected! IP: " + WiFi.localIP().toString());
    preferences.begin("wifi", false);
    preferences.putString("ssid", ssid_str);
    preferences.putString("password", password_str);
    preferences.end();
    if (debug == 1) Serial.println("[APP] WiFi credentials saved");
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
    if (debug == 1) Serial.println("[APP] WiFi connection failed");
    lv_obj_t *error_label = lv_label_create(wifi_setup_screen);
    lv_label_set_text(error_label, "Connection failed");
    lv_obj_set_style_text_color(error_label, lv_color_hex(0xFF0000), 0);
    lv_obj_align(error_label, LV_ALIGN_TOP_MID, 0, 200);
  }
}
void api_code_submit_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] API code submit button clicked");
  lv_obj_t *api_ta = (lv_obj_t*)lv_event_get_user_data(e);
  apiCode = String(lv_textarea_get_text(api_ta));
  preferences.begin("api", false);
  preferences.putString("apiCode", apiCode);
  preferences.end();
  if (debug == 1) Serial.println("[APP] API code saved");
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
  if (debug == 1) Serial.println("[APP] Location submit button clicked");
  lv_obj_t *location_ta = (lv_obj_t*)lv_event_get_user_data(e);
  String new_location = String(lv_textarea_get_text(location_ta));
  HTTPClient http;
  String url = "https://crontech.uk/api.php?weatherLocation=" + URLEncode(apiCode) + "&city_name=" + URLEncode(new_location);
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (!error) {
      location = doc["city_name"].as<String>();
      lat = String(doc["latitude"].as<float>(), 6);
      lon = String(doc["longitude"].as<float>(), 6);
      preferences.begin("location", false);
      preferences.putString("location", location);
      preferences.putString("lat", lat);
      preferences.putString("lon", lon);
      preferences.end();
      if (debug == 1) Serial.println("[APP] Weather location saved: " + location + ", lat: " + lat + ", lon: " + lon);
    } else {
      if (debug == 1) Serial.println("[APP] JSON parsing failed: " + String(error.c_str()));
    }
  } else {
    if (debug == 1) Serial.println("[APP] Weather location update failed: " + String(httpCode));
  }
  http.end();
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
  if (debug == 1) Serial.println("[APP] WiFi logout button clicked");
  preferences.begin("wifi", false);
  preferences.clear();
  preferences.end();
  if (debug == 1) Serial.println("[APP] WiFi credentials cleared");
  WiFi.disconnect();
  if (wifi_setup_screen) {
    lv_obj_del(wifi_setup_screen);
    wifi_setup_screen = nullptr;
	lv_obj_del(wifi_setup_screen);  // This will cascade-delete children, including QR
  }
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  show_wifi_setup_screen();
}
void api_logout_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] API logout button clicked");
  preferences.begin("api", false);
  preferences.clear();
  preferences.end();
  if (debug == 1) Serial.println("[APP] API code cleared");
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  show_api_code_screen();
}
void keyboard_event_cb(lv_event_t * e) {
    lv_event_code_t code = lv_event_get_code(e);
    lv_obj_t *ta = (lv_obj_t*)lv_event_get_user_data(e);
    if (code == LV_EVENT_FOCUSED) {
        if (ta && keyboard) {
            intptr_t is_number = (intptr_t)lv_obj_get_user_data(ta);
            lv_keyboard_set_mode(keyboard, is_number ? LV_KEYBOARD_MODE_NUMBER : LV_KEYBOARD_MODE_TEXT_LOWER);
            lv_keyboard_set_textarea(keyboard, ta);
            lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
            lv_obj_clear_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
            lv_obj_move_foreground(keyboard);
            // Adjust parent container (popup/screen) to ensure textarea is above keyboard
            lv_obj_t *par = lv_obj_get_parent(ta);
            if (par) {
                lv_coord_t kb_h = lv_obj_get_height(keyboard);
                lv_disp_t *disp = lv_disp_get_default();
                lv_coord_t scr_h = lv_disp_get_ver_res(disp);
                lv_area_t ta_coords;
                lv_obj_get_coords(ta, &ta_coords);
                lv_coord_t ta_bottom = ta_coords.y2;
                lv_coord_t kb_top = scr_h - kb_h;
                if (ta_bottom > kb_top) {
                    lv_coord_t overlap = ta_bottom - kb_top + 20; // 20px gap
                    lv_coord_t par_y = lv_obj_get_y(par);
                    lv_obj_set_y(par, par_y - overlap);
                }
            }
        } else {
            if (debug == 1) Serial.println("[APP] Error: Invalid textarea or keyboard in keyboard_event_cb");
        }
    } else if (code == LV_EVENT_DEFOCUSED) {
        if (keyboard) {
            lv_keyboard_set_textarea(keyboard, nullptr);
            lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
            // Reset parent to centered position if applicable
            lv_obj_t *par = lv_obj_get_parent(ta);
            if (par && (par == settings_popup || par == new_event_popup || par == location_screen || par == api_code_screen || par == wifi_setup_screen)) {
                lv_obj_align(par, LV_ALIGN_CENTER, 0, 0);
            }
        }
    }
}
void show_wifi_setup_screen() {
  if (debug == 1) Serial.println("[APP] Showing WiFi setup screen...");
  wifi_setup_screen = lv_obj_create(lv_scr_act());
  lv_obj_set_size(wifi_setup_screen, 800, 480);
  lv_obj_set_style_bg_color(wifi_setup_screen, lv_color_hex(0x000000), 0);
  int n = WiFi.scanNetworks();
  if (debug == 1) Serial.println("[APP] Found " + String(n) + " networks");
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
  lv_obj_add_event_cb(connect_btn, wifi_connect_cb, LV_EVENT_PRESSED, dropdown);
  lv_obj_t *connect_label = lv_label_create(connect_btn);
  lv_label_set_text(connect_label, "Connect");
  lv_obj_center(connect_label);
  lv_obj_set_style_text_font(connect_label, &lv_font_montserrat_14, 0);
  if (ssid != "") {
    lv_obj_t *wifi_logout_btn = lv_button_create(wifi_setup_screen);
    lv_obj_add_event_cb(wifi_logout_btn, wifi_logout_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_align(wifi_logout_btn, LV_ALIGN_TOP_MID, 0, 260);
    lv_obj_set_size(wifi_logout_btn, 120, 40);
    lv_obj_set_style_bg_color(wifi_logout_btn, lv_color_hex(0x333333), 0);
    lv_obj_t *wifi_logout_label = lv_label_create(wifi_logout_btn);
    lv_label_set_text(wifi_logout_label, "WiFi Logout");
    lv_obj_center(wifi_logout_label);
    lv_obj_set_style_text_font(wifi_logout_label, &lv_font_montserrat_14, 0);
  }
  if (!keyboard) {
    keyboard = lv_keyboard_create(lv_scr_act());
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
  }
 // UPDATED: Add QR code image under the UI, positioned at y-offset -10
  lv_obj_t *qr_img = lv_img_create(wifi_setup_screen);  // Create on the screen container
  lv_img_set_src(qr_img, &qr);
  lv_img_set_zoom(qr_img, 120);  // Scale for visibility (adjust as needed)
  lv_obj_align(qr_img, LV_ALIGN_BOTTOM_MID, 0, 90);  // Position under buttons/text areas
  lv_obj_set_style_img_recolor(qr_img, lv_color_hex(0xFFFFFF), 0);  // Ensure white for dark background

  // NEW: Add instructional text label above the QR for context and guidance
  lv_obj_t *instruction_label = lv_label_create(wifi_setup_screen);
  lv_label_set_text(instruction_label, "Register on crontech.uk to use CronTab. Scan the QR code for quick setup.");
  lv_obj_set_style_text_color(instruction_label, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_text_font(instruction_label, &lv_font_montserrat_14, 0);
  lv_label_set_long_mode(instruction_label, LV_LABEL_LONG_WRAP);  // Enable text wrapping for longer sentences
  lv_obj_set_width(instruction_label, 600);  // Set a reasonable width for wrapping on 800px screen
  lv_obj_align_to(instruction_label, qr_img, LV_ALIGN_OUT_TOP_MID, 0, 70);  // Position 20px above QR

  if (debug == 1) Serial.println("[APP] WiFi setup screen shown with QR code and instructional text");;

}


void show_api_code_screen() {
  if (debug == 1) Serial.println("[APP] Showing API code screen...");
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
  lv_obj_add_event_cb(submit_btn, api_code_submit_cb, LV_EVENT_PRESSED, api_ta);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  if (!keyboard) {
    keyboard = lv_keyboard_create(lv_scr_act());
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
  }
 // UPDATED: Add QR code image under the UI, positioned at y-offset -10
  lv_obj_t *qr_img = lv_img_create(api_code_screen);  // Create on the screen container
  lv_img_set_src(qr_img, &qr);
  lv_img_set_zoom(qr_img, 150);  // Scale for visibility (adjust as needed)
  lv_obj_align(qr_img, LV_ALIGN_BOTTOM_MID, 0, 50);  // Position under buttons/text areas
  lv_obj_set_style_img_recolor(qr_img, lv_color_hex(0xFFFFFF), 0);  // Ensure white for dark background

  // NEW: Add instructional text label above the QR for context and guidance
  lv_obj_t *instruction_label = lv_label_create(api_code_screen);
  lv_label_set_text(instruction_label, "Scan QR for quick API code.");
  lv_obj_set_style_text_color(instruction_label, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_text_font(instruction_label, &lv_font_montserrat_14, 0);
  lv_label_set_long_mode(instruction_label, LV_LABEL_LONG_WRAP);  // Enable text wrapping for longer sentences
  lv_obj_set_width(instruction_label, 600);  // Set a reasonable width for wrapping on 800px screen
  lv_obj_align_to(instruction_label, qr_img, LV_ALIGN_OUT_TOP_MID, 0, -100);  // Position 20px above QR

  if (debug == 1) Serial.println("[APP] API code screen shown with QR code and instructional text");
}
void show_location_screen() {
  if (debug == 1) Serial.println("[APP] Showing location screen...");
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
  lv_obj_add_event_cb(submit_btn, location_submit_cb, LV_EVENT_PRESSED, location_ta);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  if (!keyboard) {
    keyboard = lv_keyboard_create(lv_scr_act());
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
  }
}
void close_settings_cb(lv_event_t * e) {
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
}
void factory_reset_cb(lv_event_t *e);
void show_settings_popup() {
    if (debug == 1) Serial.println("[APP] Showing settings popup...");
    settings_popup = lv_obj_create(lv_scr_act());
    lv_obj_set_size(settings_popup, 550, 300); // Reduced size for compactness (from 600x380)
    lv_obj_align(settings_popup, LV_ALIGN_CENTER, 0, 0);
    lv_obj_set_style_bg_color(settings_popup, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_color(settings_popup, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_border_width(settings_popup, 2, 0);
    lv_obj_set_scroll_dir(settings_popup, LV_DIR_VER);
    lv_obj_set_scrollbar_mode(settings_popup, LV_SCROLLBAR_MODE_AUTO);
    lv_obj_set_scroll_snap_y(settings_popup, LV_SCROLL_SNAP_CENTER);
    int y_offset = 10;
    // QR Code Image (50% smaller, moved up by 100px)
    lv_obj_t *qr_img = lv_img_create(settings_popup);
    lv_img_set_src(qr_img, &qr);
    lv_obj_align(qr_img, LV_ALIGN_TOP_MID, 0, y_offset - 120); // Move up by 100px
    lv_img_set_zoom(qr_img, 148); // 50% scale (128 = 50% of 256)
    y_offset += 80; // Adjust for smaller image (~80px original height / 2)
    // Additional shift for subsequent elements
    y_offset += 50; // Shift all following elements down by 50px
    // UI Darkness Slider
    lv_obj_t *darkness_label = lv_label_create(settings_popup);
    lv_label_set_text(darkness_label, "UI Brightness:");
    lv_obj_align(darkness_label, LV_ALIGN_TOP_LEFT, 20, y_offset);
    lv_obj_set_style_text_font(darkness_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(darkness_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *darkness_slider = lv_slider_create(settings_popup);
    lv_slider_set_range(darkness_slider, 0, 100);
    lv_slider_set_value(darkness_slider, g_ui_darkness, LV_ANIM_OFF);
    lv_obj_set_width(darkness_slider, 200); // Reduced width to fit smaller popup
    lv_obj_align(darkness_slider, LV_ALIGN_TOP_MID, 0, y_offset + 20);
    lv_obj_add_event_cb(darkness_slider, darkness_slider_cb, LV_EVENT_VALUE_CHANGED, NULL);
    y_offset += 60;
    // Firmware Version (restored to top)
    lv_obj_t *version_settings_label = lv_label_create(settings_popup);
    lv_label_set_text(version_settings_label, ("Firmware: " + currentFirmwareVersion).c_str());
    lv_obj_align(version_settings_label, LV_ALIGN_TOP_MID, 0, y_offset);
    lv_obj_set_style_text_font(version_settings_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(version_settings_label, lv_color_hex(0xFFFFFF), 0);
    y_offset += 30;
    // Weather Location
    lv_obj_t *weather_cont = lv_obj_create(settings_popup);
    lv_obj_set_size(weather_cont, 450, 90); // Reduced width to fit smaller popup
    lv_obj_align(weather_cont, LV_ALIGN_TOP_MID, 0, y_offset);
    lv_obj_set_style_bg_color(weather_cont, lv_color_hex(0x55a3ff), 0);
    lv_obj_set_style_bg_grad_color(weather_cont, lv_color_hex(0x007acc), 0);
    lv_obj_set_style_bg_grad_dir(weather_cont, LV_GRAD_DIR_HOR, 0);
    lv_obj_set_style_radius(weather_cont, 8, 0);
    lv_obj_set_style_pad_all(weather_cont, 5, 0);
    lv_obj_set_flex_flow(weather_cont, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_style_pad_row(weather_cont, 5, 0);
    lv_obj_set_scrollbar_mode(weather_cont, LV_SCROLLBAR_MODE_OFF);
    lv_obj_t *weather_title = lv_label_create(weather_cont);
    lv_label_set_text(weather_title, "Weather Location");
    lv_obj_set_style_text_font(weather_title, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(weather_title, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *location_row = lv_obj_create(weather_cont);
    lv_obj_set_size(location_row, LV_PCT(100), LV_SIZE_CONTENT);
    lv_obj_set_style_bg_opa(location_row, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(location_row, 0, 0);
    lv_obj_set_flex_flow(location_row, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(location_row, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_t *location_label = lv_label_create(location_row);
    lv_label_set_text(location_label, "City:");
    lv_obj_set_style_text_font(location_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(location_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *location_ta = lv_textarea_create(location_row);
    lv_textarea_set_one_line(location_ta, true);
    lv_textarea_set_placeholder_text(location_ta, "City");
    lv_textarea_set_text(location_ta, location.c_str());
    lv_obj_set_width(location_ta, 180); // Reduced width to fit smaller popup
    lv_obj_set_style_text_font(location_ta, &lv_font_montserrat_14, 0);
    lv_obj_set_user_data(location_ta, (void*)0);
    lv_obj_add_event_cb(location_ta, keyboard_event_cb, LV_EVENT_FOCUSED, location_ta);
    lv_obj_add_event_cb(location_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, location_ta);
    y_offset += 90;
    // ParcelBox
    lv_obj_t *parcelbox_cont = lv_obj_create(settings_popup);
    lv_obj_set_size(parcelbox_cont, 450, 100); // Reduced size to fit smaller popup
    lv_obj_align(parcelbox_cont, LV_ALIGN_TOP_MID, 0, y_offset);
    lv_obj_set_style_bg_color(parcelbox_cont, lv_color_hex(0x00b894), 0);
    lv_obj_set_style_bg_grad_color(parcelbox_cont, lv_color_hex(0x00a085), 0);
    lv_obj_set_style_bg_grad_dir(parcelbox_cont, LV_GRAD_DIR_HOR, 0);
    lv_obj_set_style_radius(parcelbox_cont, 8, 0);
    lv_obj_set_style_pad_all(parcelbox_cont, 5, 0);
    lv_obj_set_flex_flow(parcelbox_cont, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_style_pad_row(parcelbox_cont, 5, 0);
    lv_obj_set_scrollbar_mode(parcelbox_cont, LV_SCROLLBAR_MODE_OFF);
    lv_obj_t *parcelbox_title = lv_label_create(parcelbox_cont);
    lv_label_set_text(parcelbox_title, "ParcelBox");
    lv_obj_set_style_text_font(parcelbox_title, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(parcelbox_title, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *username_row = lv_obj_create(parcelbox_cont);
    lv_obj_set_size(username_row, LV_PCT(100), LV_SIZE_CONTENT);
    lv_obj_set_style_bg_opa(username_row, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(username_row, 0, 0);
    lv_obj_set_flex_flow(username_row, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(username_row, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_t *username_label = lv_label_create(username_row);
    lv_label_set_text(username_label, "Username:");
    lv_obj_set_style_text_font(username_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(username_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *username_ta = lv_textarea_create(username_row);
    lv_textarea_set_one_line(username_ta, true);
    lv_textarea_set_placeholder_text(username_ta, "Username");
    lv_textarea_set_text(username_ta, username.c_str());
    lv_obj_set_width(username_ta, 180); // Reduced width to fit smaller popup
    lv_obj_set_style_text_font(username_ta, &lv_font_montserrat_14, 0);
    lv_obj_set_user_data(username_ta, (void*)0);
    lv_obj_add_event_cb(username_ta, keyboard_event_cb, LV_EVENT_FOCUSED, username_ta);
    lv_obj_add_event_cb(username_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, username_ta);
    lv_obj_t *device_id_row = lv_obj_create(parcelbox_cont);
    lv_obj_set_size(device_id_row, LV_PCT(100), LV_SIZE_CONTENT);
    lv_obj_set_style_bg_opa(device_id_row, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(device_id_row, 0, 0);
    lv_obj_set_flex_flow(device_id_row, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(device_id_row, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_t *device_id_label = lv_label_create(device_id_row);
    lv_label_set_text(device_id_label, "Device ID:");
    lv_obj_set_style_text_font(device_id_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(device_id_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *device_id_ta = lv_textarea_create(device_id_row);
    lv_textarea_set_one_line(device_id_ta, true);
    lv_textarea_set_placeholder_text(device_id_ta, "Device ID");
    lv_textarea_set_text(device_id_ta, device_id.c_str());
    lv_obj_set_width(device_id_ta, 180); // Reduced width to fit smaller popup
    lv_obj_set_style_text_font(device_id_ta, &lv_font_montserrat_14, 0);
    lv_obj_set_user_data(device_id_ta, (void*)0);
    lv_obj_add_event_cb(device_id_ta, keyboard_event_cb, LV_EVENT_FOCUSED, device_id_ta);
    lv_obj_add_event_cb(device_id_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, device_id_ta);
    lv_obj_t *preview_row = lv_obj_create(parcelbox_cont);
    lv_obj_set_size(preview_row, LV_PCT(100), LV_SIZE_CONTENT);
    lv_obj_set_style_bg_opa(preview_row, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(preview_row, 0, 0);
    lv_obj_t *preview_label_title = lv_label_create(preview_row);
    lv_label_set_text(preview_label_title, "Preview:");
    lv_obj_set_style_text_font(preview_label_title, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(preview_label_title, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *preview_label = lv_label_create(preview_row);
    lv_label_set_text(preview_label, device_id.c_str());
    lv_obj_set_style_text_font(preview_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(preview_label, lv_color_hex(0xFFFF00), 0);
    lv_obj_add_event_cb(device_id_ta, device_id_preview_cb, LV_EVENT_VALUE_CHANGED, preview_label);
    y_offset += 110; // Reduced to fit smaller popup
    // Temperature Adjustment
    lv_obj_t *temp_cont = lv_obj_create(settings_popup);
    lv_obj_set_size(temp_cont, 450, 90);
    lv_obj_align(temp_cont, LV_ALIGN_TOP_MID, 0, y_offset);
    lv_obj_set_style_bg_color(temp_cont, lv_color_hex(0xfd79a8), 0);
    lv_obj_set_style_bg_grad_color(temp_cont, lv_color_hex(0xe84393), 0);
    lv_obj_set_style_bg_grad_dir(temp_cont, LV_GRAD_DIR_HOR, 0);
    lv_obj_set_style_radius(temp_cont, 8, 0);
    lv_obj_set_style_pad_all(temp_cont, 5, 0);
    lv_obj_set_flex_flow(temp_cont, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_style_pad_row(temp_cont, 5, 0);
    lv_obj_set_scrollbar_mode(temp_cont, LV_SCROLLBAR_MODE_OFF);
    lv_obj_t *temp_title = lv_label_create(temp_cont);
    lv_label_set_text(temp_title, "Temperature Adjustment");
    lv_obj_set_style_text_font(temp_title, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(temp_title, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *temp_row = lv_obj_create(temp_cont);
    lv_obj_set_size(temp_row, LV_PCT(100), LV_SIZE_CONTENT);
    lv_obj_set_style_bg_opa(temp_row, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(temp_row, 0, 0);
    lv_obj_set_flex_flow(temp_row, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(temp_row, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_t *temp_label = lv_label_create(temp_row);
    lv_label_set_text(temp_label, "Adjust (°C):");
    lv_obj_set_style_text_font(temp_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(temp_label, lv_color_hex(0xFFFFFF), 0);
    lv_obj_t *temp_adjust_ta = lv_textarea_create(temp_row);
    lv_textarea_set_one_line(temp_adjust_ta, true);
    lv_textarea_set_placeholder_text(temp_adjust_ta, "0");
    lv_textarea_set_text(temp_adjust_ta, String(temp_adjust).c_str());
    lv_obj_set_width(temp_adjust_ta, 180);
    lv_obj_set_style_text_font(temp_adjust_ta, &lv_font_montserrat_14, 0);
    lv_obj_set_user_data(temp_adjust_ta, (void*)1);
    lv_obj_add_event_cb(temp_adjust_ta, keyboard_event_cb, LV_EVENT_FOCUSED, temp_adjust_ta);
    lv_obj_add_event_cb(temp_adjust_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, temp_adjust_ta);
    y_offset += 90;
    // Buttons
    lv_obj_t *wifi_logout_btn = lv_button_create(settings_popup);
    lv_obj_add_event_cb(wifi_logout_btn, wifi_logout_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_align(wifi_logout_btn, LV_ALIGN_TOP_LEFT, 20, y_offset);
    lv_obj_set_size(wifi_logout_btn, 90, 40); // Reduced size to fit smaller popup
    lv_obj_set_style_bg_color(wifi_logout_btn, lv_color_hex(0x333333), 0);
    lv_obj_t *wifi_logout_label = lv_label_create(wifi_logout_btn);
    lv_label_set_text(wifi_logout_label, "WiFi Logout");
    lv_obj_center(wifi_logout_label);
    lv_obj_set_style_text_font(wifi_logout_label, &lv_font_montserrat_14, 0); // Smaller font
    lv_obj_t *api_logout_btn = lv_button_create(settings_popup);
    lv_obj_add_event_cb(api_logout_btn, api_logout_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_align(api_logout_btn, LV_ALIGN_TOP_RIGHT, -20, y_offset);
    lv_obj_set_size(api_logout_btn, 90, 40); // Reduced size to fit smaller popup
    lv_obj_set_style_bg_color(api_logout_btn, lv_color_hex(0x333333), 0);
    lv_obj_t *api_logout_label = lv_label_create(api_logout_btn);
    lv_label_set_text(api_logout_label, "API Logout");
    lv_obj_center(api_logout_label);
    lv_obj_set_style_text_font(api_logout_label, &lv_font_montserrat_14, 0); // Smaller font
    lv_obj_t *submit_btn = lv_button_create(settings_popup);
    lv_obj_align(submit_btn, LV_ALIGN_TOP_LEFT, 20, y_offset + 40);
    lv_obj_set_size(submit_btn, 90, 40); // Reduced size to fit smaller popup
    lv_obj_add_event_cb(submit_btn, save_settings_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_t *submit_label = lv_label_create(submit_btn);
    lv_label_set_text(submit_label, "Save");
    lv_obj_center(submit_label);
    lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0); // Smaller font
    lv_obj_t *close_btn = lv_button_create(settings_popup);
    lv_obj_align(close_btn, LV_ALIGN_TOP_RIGHT, -20, y_offset + 40);
    lv_obj_set_size(close_btn, 90, 40); // Reduced size to fit smaller popup
    lv_obj_set_style_bg_color(close_btn, lv_color_hex(0xFF0000), 0);
    lv_obj_add_event_cb(close_btn, close_settings_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_t *close_label = lv_label_create(close_btn);
    lv_label_set_text(close_label, "Close");
    lv_obj_center(close_label);
    lv_obj_set_style_text_font(close_label, &lv_font_montserrat_14, 0); // Smaller font
    lv_obj_t *factory_reset_btn = lv_button_create(settings_popup);
    lv_obj_add_event_cb(factory_reset_btn, factory_reset_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_align(factory_reset_btn, LV_ALIGN_TOP_MID, 0, y_offset + 40);
    lv_obj_set_size(factory_reset_btn, 70, 20); // Reduced size to fit smaller popup
    lv_obj_set_style_bg_color(factory_reset_btn, lv_color_hex(0xFF0000), 0);
    lv_obj_t *factory_reset_label = lv_label_create(factory_reset_btn);
    lv_label_set_text(factory_reset_label, "Reset");
    lv_obj_center(factory_reset_label);
    lv_obj_set_style_text_font(factory_reset_label, &lv_font_montserrat_14, 0); // Smaller font
    y_offset += 80; // Adjusted for smaller buttons
    SettingsUI *sui = new SettingsUI();
    sui->location_ta = location_ta;
    sui->username_ta = username_ta;
    sui->device_id_ta = device_id_ta;
    sui->temp_adjust_ta = temp_adjust_ta;
    // Update the event callback for submit_btn to use the SettingsUI structure
    lv_obj_add_event_cb(submit_btn, save_settings_cb, LV_EVENT_PRESSED, sui);
    // Keyboard setup with dynamic positioning
    if (!keyboard) {
      keyboard = lv_keyboard_create(lv_scr_act());
      lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
      lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0); // Smaller font to match
    }
}
void factory_reset_cb(lv_event_t *e) {
  const char* namespaces[] = {"wifi", "api", "location", "firmware", "ui", "cloudapps", "event_states"};
  for (int i = 0; i < 7; i++) {
    preferences.begin(namespaces[i], false);
    preferences.clear();
    preferences.end();
  }
  WiFi.disconnect(true);
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  ESP.restart();
}
void settings_btn_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] Settings button clicked");
  lv_event_code_t code = lv_event_get_code(e);
  if (code == LV_EVENT_PRESSED) {
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
  if (debug == 1) Serial.println("[APP] New event button clicked");
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
    if (debug == 1) Serial.println("[APP] Failed to get local time for preselecting start date");
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
  time_t start_time = mktime(&start_tm);
  time_t end_time = start_time + 15 * 60;
  struct tm *end_tm = localtime(&end_time);
  int end_year = end_tm->tm_year + 1900;
  int end_month = end_tm->tm_mon + 1;
  int end_day = end_tm->tm_mday;
  int end_hour = end_tm->tm_hour;
  int end_min = (end_tm->tm_min / 15) * 15;
  lv_obj_t *remind_label = lv_label_create(new_event_popup);
  lv_label_set_text(remind_label, "Remind before (5m,2h,1d):");
  lv_obj_align(remind_label, LV_ALIGN_TOP_LEFT, 50, 170);
  lv_obj_set_style_text_font(remind_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(remind_label, lv_color_hex(0xFFFFFF), 0); // Brighter text
  ui->remind_before_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->remind_before_ta, true);
  lv_textarea_set_max_length(ui->remind_before_ta, 4);
  lv_textarea_set_placeholder_text(ui->remind_before_ta, "0m");
  lv_textarea_set_text(ui->remind_before_ta, "0m");
  lv_obj_set_width(ui->remind_before_ta, 100);
  lv_obj_align(ui->remind_before_ta, LV_ALIGN_TOP_LEFT, 250, 170);
  lv_obj_set_style_text_font(ui->remind_before_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->remind_before_ta, (void*)0);
  lv_obj_add_event_cb(ui->remind_before_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->remind_before_ta);
  lv_obj_add_event_cb(ui->remind_before_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->remind_before_ta);
  lv_obj_t *start_label = lv_label_create(new_event_popup);
  lv_label_set_text(start_label, "Start:");
  lv_obj_align(start_label, LV_ALIGN_TOP_LEFT, 50, 210);
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
  lv_obj_align(ui->start_year_ta, LV_ALIGN_TOP_LEFT, 100, 210);
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
  lv_obj_align(ui->start_month_ta, LV_ALIGN_TOP_LEFT, 210, 210);
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
  lv_obj_align(ui->start_day_ta, LV_ALIGN_TOP_LEFT, 280, 210);
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
  lv_obj_align(ui->start_hour_ta, LV_ALIGN_TOP_LEFT, 350, 210);
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
  lv_obj_align(ui->start_min_ta, LV_ALIGN_TOP_LEFT, 420, 210);
  lv_obj_set_style_text_font(ui->start_min_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->start_min_ta, (void*)1);
  lv_obj_add_event_cb(ui->start_min_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->start_min_ta);
  lv_obj_add_event_cb(ui->start_min_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->start_min_ta);
  lv_obj_t *end_label = lv_label_create(new_event_popup);
  lv_label_set_text(end_label, "End:");
  lv_obj_align(end_label, LV_ALIGN_TOP_LEFT, 50, 260);
  lv_obj_set_style_text_font(end_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(end_label, lv_color_hex(0xFFFFFF), 0); // Brighter text
  ui->end_year_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->end_year_ta, true);
  lv_textarea_set_max_length(ui->end_year_ta, 4);
  snprintf(buf, sizeof(buf), "%04d", end_year);
  lv_textarea_set_text(ui->end_year_ta, buf);
  lv_textarea_set_placeholder_text(ui->end_year_ta, "YYYY");
  lv_obj_set_width(ui->end_year_ta, 100);
  lv_obj_align(ui->end_year_ta, LV_ALIGN_TOP_LEFT, 100, 260);
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
  lv_obj_align(ui->end_month_ta, LV_ALIGN_TOP_LEFT, 210, 260);
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
  lv_obj_align(ui->end_day_ta, LV_ALIGN_TOP_LEFT, 280, 260);
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
  lv_obj_align(ui->end_hour_ta, LV_ALIGN_TOP_LEFT, 350, 260);
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
  lv_obj_align(ui->end_min_ta, LV_ALIGN_TOP_LEFT, 420, 260);
  lv_obj_set_style_text_font(ui->end_min_ta, &lv_font_montserrat_14, 0);
  lv_obj_set_user_data(ui->end_min_ta, (void*)1);
  lv_obj_add_event_cb(ui->end_min_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->end_min_ta);
  lv_obj_add_event_cb(ui->end_min_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->end_min_ta);
  lv_obj_t *submit_btn = lv_button_create(new_event_popup);
  lv_obj_align(submit_btn, LV_ALIGN_TOP_MID, -70, 320);
  lv_obj_set_size(submit_btn, 120, 40);
  lv_obj_set_style_bg_color(submit_btn, lv_color_hex(0x00FF00), 0);
  lv_obj_add_event_cb(submit_btn, new_event_submit_cb, LV_EVENT_PRESSED, ui);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  lv_obj_t *cancel_btn = lv_button_create(new_event_popup);
  lv_obj_align(cancel_btn, LV_ALIGN_TOP_MID, 70, 320);
  lv_obj_set_size(cancel_btn, 120, 40);
  lv_obj_set_style_bg_color(cancel_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_add_event_cb(cancel_btn, new_event_cancel_cb, LV_EVENT_PRESSED, ui);
  lv_obj_t *cancel_label = lv_label_create(cancel_btn);
  lv_label_set_text(cancel_label, "Cancel");
  lv_obj_center(cancel_label);
  lv_obj_set_style_text_font(cancel_label, &lv_font_montserrat_14, 0);
  if (!keyboard) {
    keyboard = lv_keyboard_create(lv_scr_act());
    lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
  }
}
void new_event_submit_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] New event submit button clicked");
  NewEventUI *ui = (NewEventUI*)lv_event_get_user_data(e);
  if (!ui) {
    if (debug == 1) Serial.println("[APP] Error: UI structure is null");
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
    if (debug == 1) Serial.println("[APP] Error: One or more UI elements are null");
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
    if (debug == 1) Serial.println("[APP] Error: Invalid date/time selection");
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
  String url1 = "https://crontech.uk/api.php?unique_code=" + apiCode +
               "&title=" + encoded_title +
               "&message_description=" + encoded_description +
               "&from=" + encoded_from +
               "&to=" + encoded_to +
               "&remind_before=" + encoded_remind_before;
  if (debug == 1) Serial.println("[APP] Create URL: " + url1);
  HTTPClient http;
  http.begin(url1);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String response = http.getString();
    if (debug == 1) Serial.println("[APP] Event submission response response: " + response);
    fetchEvents();
    updateEventDisplay(calendar);
  } else {
    String response = http.getString();
    String error_message = "Submission failed: " + response;
    if (debug == 1) Serial.println("[APP] Event submission failed: HTTP " + String(httpCode) + ", Response: " + response);
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
  if (debug == 1) Serial.println("[APP] New event cancel button clicked");
  NewEventUI *ui = (NewEventUI*)lv_event_get_user_data(e);
  if (ui) {
    delete ui;
  }
  lv_obj_add_flag(new_event_popup, LV_OBJ_FLAG_HIDDEN);
  lv_obj_del(new_event_popup);
  new_event_popup = nullptr;
}
void updateDateTimeLabel() {
  if (!date_time_label) {
    if (debug == 1) Serial.println("[APP] Error: date_time_label is null in updateDateTimeLabel");
    return;
  }
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    if (debug == 1) Serial.println("[APP] Failed to get local time in updateDateTimeLabel");
    lv_label_set_text(date_time_label, "Unknown Date/Time");
    return;
  }
  char date_time_str[32];
  strftime(date_time_str, sizeof(date_time_str), "%H:%M", &timeinfo); // Include seconds for 1-second precision
  lv_label_set_text(date_time_label, date_time_str);
  if (debug == 1) {Serial.println("[APP] Updated date-time label to: " + String(date_time_str));}
  lv_obj_invalidate(lv_scr_act());
}
// Existing constant remains as is (do not redefine)
// const unsigned long dateTimeUpdateInterval = 60000; // Already defined earlier; keep for 1-minute notifications
// New constants and variables for separate 1-second date-time refresh
const unsigned long timeLabelUpdateInterval = 1000; // Update every 1 second
static unsigned long lastTimeLabelUpdate = 0;
// Updated updateMonthLabel function
void updateMonthLabel(lv_obj_t *calendar) {
  if (!calendar) {
    if (debug == 1) Serial.println("[APP] Error: Calendar object is null in updateMonthLabel");
    if (month_label) {
      lv_label_set_text(month_label, "Unknown Month");
    }
    if (build_version_label) {
      lv_label_set_text(build_version_label, ("V." + String(build_version)).c_str());
    }
    return;
  }
  if (!month_label) {
    if (debug == 1) Serial.println("[APP] Error: month_label is null in updateMonthLabel");
    return;
  }
  if (!build_version_label) {
    if (debug == 1) Serial.println("[APP] Error: build_version_label is null in updateMonthLabel");
    return;
  }
  const lv_calendar_date_t *showed = lv_calendar_get_showed_date(calendar);
  if (!showed) {
    if (debug == 1) Serial.println("[APP] Error: Failed to get showed date");
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
  lv_label_set_text(build_version_label, ("V." + String(build_version)).c_str());
  if (debug == 1) Serial.println("[APP] Updated month label to: " + String(buf));
  if (debug == 1) Serial.println("[APP] Updated build version label to: Build: " + String(build_version));
  lv_obj_invalidate(lv_scr_act());
}
// New function to update holiday label
void updateHolidayLabel() {
  if (!holiday_label) {
    if (debug == 1) Serial.println("[APP] Error: holiday_label is null");
    return;
  }
  const lv_calendar_date_t *showed = lv_calendar_get_showed_date(calendar);
  if (!showed) {
    if (debug == 1) Serial.println("[APP] Error: Failed to get showed date for holidays");
    return;
  }
  int year = showed->year;
  int month = showed->month;
  bool has_holiday = false;
  String content_str = "";
  for (int i = 0; i < numHolidays; i++) {
    if (holidays[i].year == year && holidays[i].month == month) {
      has_holiday = true;
      String processed_title = holidays[i].title;
      processed_title.replace("'", " ");  // Replace apostrophe with space to avoid glyph issues
      if (content_str != "") content_str += ", ";
      content_str += processed_title + " (" + String(holidays[i].day) + ")";
    }
  }

  // Enable markup recoloring on the label
  lv_label_set_recolor(holiday_label, true);

  // Configure for full visibility: full width, left alignment, and wrapping
  lv_obj_set_width(holiday_label, LV_PCT(100));
  lv_obj_set_style_text_align(holiday_label, LV_TEXT_ALIGN_LEFT, 0);
  lv_label_set_long_mode(holiday_label, LV_LABEL_LONG_WRAP);

  String full_text;
  if (!has_holiday) {
    // Green markup for the entire no-holidays message
    full_text = String("#05750a No bank holidays this month #");
  } else {
    // Orange markup for prefix, maroon for content
    full_text = String("#050975 Bank Holidays: # #a8020a ") + content_str + " #";
  }

  // Set the marked-up text
  lv_label_set_text(holiday_label, full_text.c_str());

  // Invalidate for redraw
  lv_obj_invalidate(lv_scr_act());
  if (debug == 1) Serial.println("[APP] Holiday label updated with markup: " + full_text);
}

void checkFirmwareUpdate() {
  if (debug == 1) Serial.println("[OTA] Starting firmware update check...");
  printMemoryUsage();
  // Check WiFi status
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[OTA] WiFi not connected (status: " + String(WiFi.status()) + "), skipping update check");
    return;
  }
  if (debug == 1) Serial.println("[OTA] WiFi connected, IP: " + WiFi.localIP().toString());
  // Perform HTTP request
  if (debug == 1) Serial.println("[OTA] Fetching version.json from https://crontech.uk/update/version.json");
  client.setInsecure(); // For testing; use CA certificate in production
  HTTPClient http;
  String url = "https://crontech.uk/update/version.json";
  http.begin(client, url);
  if (debug == 1) Serial.println("[OTA] HTTP request initiated");
  int httpCode = http.GET();
  // Check HTTP response
  if (httpCode != HTTP_CODE_OK) {
    if (debug == 1) Serial.println("[OTA] HTTP request failed with code: " + String(httpCode));
    http.end();
    return;
  }
  if (debug == 1) Serial.println("[OTA] HTTP request successful (200 OK)");
  // Parse JSON response
  String payload = http.getString();
  if (debug == 1) Serial.println("[OTA] Response payload: " + payload);
  JsonDocument doc;
  DeserializationError error = deserializeJson(doc, payload);
  if (error) {
    if (debug == 1) Serial.println("[OTA] JSON parsing failed: " + String(error.c_str()));
    http.end();
    return;
  }
  if (debug == 1) Serial.println("[OTA] JSON parsing successful");
  // Extract version and URL
  latestFirmwareVersion = doc["version"].as<String>();
  firmwareUrl = doc["url"].as<String>();
  if (debug == 1) Serial.println("[OTA] Current firmware version: " + currentFirmwareVersion);
  if (debug == 1) Serial.println("[OTA] Latest firmware version: " + latestFirmwareVersion);
  if (debug == 1) Serial.println("[OTA] Firmware URL: " + firmwareUrl);
  printMemoryUsage();
  http.end();
}
void updateFirmware() {
  if (debug == 1) Serial.println("[OTA] Starting firmware update...");
  printMemoryUsage();
  // Proceed with actual firmware update
  if (debug == 1) Serial.println("[OTA] Starting actual firmware download...");
  client.setInsecure(); // For testing; use CA certificate in production
  HTTPClient http;
  http.begin(client, firmwareUrl);
  int httpCode = http.GET();
  if (httpCode != HTTP_CODE_OK) {
    if (debug == 1) Serial.println("[OTA] Failed to download firmware: HTTP " + String(httpCode));
    http.end();
    is_ota_updating = false; // Reset flag on failure
    return;
  }
  int contentLength = http.getSize();
  if (debug == 1) Serial.println("[OTA] Firmware size: " + String(contentLength) + " bytes");
  if (contentLength <= 0) {
    if (debug == 1) Serial.println("[OTA] Invalid content length");
    http.end();
    is_ota_updating = false; // Reset flag on failure
    return;
  }
  if (!Update.begin(contentLength)) {
    if (debug == 1) Serial.println("[OTA] Not enough space for update");
    http.end();
    is_ota_updating = false; // Reset flag on failure
    return;
  }
  WiFiClient *stream = http.getStreamPtr();
  size_t written = 0;
  uint8_t buff[512];
  while (http.connected() && written < contentLength) {
    size_t size = stream->available();
    if (size) {
      int c = stream->readBytes(buff, min(sizeof(buff), size));
      if (Update.write(buff, c) != c) {
        if (debug == 1) Serial.println("[OTA] Error writing to flash");
        http.end();
        is_ota_updating = false; // Reset flag on failure
        return;
      }
      written += c;
      delay(1);
    }
  }
  if (written != contentLength) {
    if (debug == 1) Serial.println("[OTA] Incomplete download");
    http.end();
    is_ota_updating = false; // Reset flag on failure
    return;
  }
  if (!Update.end(true)) {
    if (debug == 1) Serial.println("[OTA] Error finalizing update");
    http.end();
    is_ota_updating = false; // Reset flag on failure
    return;
  }
  // Save new firmware version
  preferences.begin("firmware", false);
  preferences.putString("version", latestFirmwareVersion);
  preferences.end();
  if (debug == 1) Serial.println("[OTA] Firmware version saved: " + latestFirmwareVersion);
  if (debug == 1) Serial.println("[OTA] Update successful, rebooting...");
  delay(1000);
  ESP.restart();
  http.end();
}
// Modified update_btn_cb function
void update_btn_cb(lv_event_t *e) {
  if (debug == 1) Serial.println("[OTA] Update button clicked");
  if (firmware_update_btn) {
    lv_obj_add_flag(firmware_update_btn, LV_OBJ_FLAG_HIDDEN); // Hide update button
    if (debug == 1) Serial.println("[OTA] Update button hidden before popup");
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
    if (debug == 1) Serial.println("[OTA] Update Now button clicked");
    // Change button to red and set text to "Updating ..."
    lv_obj_t *btn = (lv_obj_t*)lv_event_get_target(e);
    lv_obj_set_style_bg_color(btn, lv_color_hex(0xFF0000), 0);
    lv_obj_t *label = lv_obj_get_child(btn, 0);
    lv_label_set_text(label, "Updating ...");
    // Set OTA flag to pause screen refreshes
    is_ota_updating = true;
    if (debug == 1) Serial.println("[OTA] OTA flag set; screen refresh paused.");
    loop_display(); // Refresh display to show changes
    // Countdown 5 seconds
    for (int i = 5; i >= 1; i--) {
      lv_label_set_text_fmt(label, "Updating ... %d", i);
      loop_display(); // Refresh display
      delay(1000);
    }
    // Hide and delete popup
    if (update_popup) {
      lv_obj_add_flag(update_popup, LV_OBJ_FLAG_HIDDEN); // Hide popup
      lv_obj_del(update_popup); // Delete popup to free memory
      update_popup = nullptr;
      if (debug == 1) Serial.println("[OTA] Update popup removed");
    }
    updateFirmware(); // Proceed with firmware update
  }, LV_EVENT_PRESSED, NULL);
  lv_obj_t *update_now_label = lv_label_create(update_now_btn);
  lv_label_set_text(update_now_label, "Update Now");
  lv_obj_center(update_now_label);
  lv_obj_set_style_text_font(update_now_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(update_now_label, lv_color_hex(0xFFFFFF), 0); // White text for contrast
  if (debug == 1) Serial.println("[OTA] Update popup created with Update Now button");
}
static int showed_year;
static int showed_month;
void snooze_reminder_cb(lv_event_t *e) {
  int index = (intptr_t)lv_event_get_user_data(e);
  time(&events[index].last_reminder_time);
  if (debug == 1) Serial.printf("[DEBUG] Snooze reminder for event %d: updated last_reminder_time to %ld\n", index, events[index].last_reminder_time);
  // Generate short key using hash
  String full_id = events[index].start + "_" + events[index].summary;
  unsigned long hash = hashString(full_id);
  String base_key = "e" + String(hash % 100000000); // "e" + up to 8 digits (9 chars total)
  // Save to preferences
  preferences.begin("event_states", false);
  preferences.putInt((base_key + "c").c_str(), events[index].reminder_count);
  preferences.putLong((base_key + "l").c_str(), events[index].last_reminder_time);
  preferences.putBool((base_key + "n").c_str(), events[index].notified);
  preferences.end();
  close_event_details_cb(e);
}
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
  rearrange_calendar_parts(calendar);
  updateMonthLabel(calendar);
  fetchBankHolidays();
  updateHolidayLabel();
  update_today_highlight(calendar);
  
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
  rearrange_calendar_parts(calendar);
  updateMonthLabel(calendar);
  fetchBankHolidays();
  updateHolidayLabel();
  update_today_highlight(calendar);
}
void updateFirmwareButton() {
  if (latestFirmwareVersion != "" && latestFirmwareVersion != currentFirmwareVersion) {
    if (!firmware_update_btn) {
      firmware_update_btn = lv_button_create(button_bar);
      lv_obj_add_event_cb(firmware_update_btn, update_btn_cb, LV_EVENT_PRESSED, NULL);
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
void darkness_slider_cb(lv_event_t *e) {
  lv_obj_t *slider = (lv_obj_t*)lv_event_get_target(e);
  g_ui_darkness = lv_slider_get_value(slider);
  // Calculate grayscale
  uint8_t gray = 255 - (g_ui_darkness * 255 / 100);
  lv_color_t bg_color = lv_color_make(gray, gray, gray);
  lv_color_t text_color = (g_ui_darkness > 50) ? lv_color_white() : lv_color_black();
  // Apply to main screen
  lv_obj_set_style_bg_color(lv_scr_act(), bg_color, 0);
  lv_obj_set_style_bg_opa(lv_scr_act(), LV_OPA_100, 0);
  // Apply to labels and calendar text
  if (month_label) lv_obj_set_style_text_color(month_label, text_color, 0);
  if (date_time_label) lv_obj_set_style_text_color(date_time_label, text_color, 0);
  if (calendar) {
    lv_obj_set_style_text_color(calendar, text_color, LV_PART_ITEMS | LV_STATE_DEFAULT);
  }
  // Redraw weather and events to apply changes
  updateWeatherDisplay();
  updateEventDisplay(calendar);
  // Save to preferences
  preferences.begin("ui", false);
  preferences.putInt("ui_darkness", g_ui_darkness);
  preferences.end();
}
void setup_calendar() {
  if (debug == 1) Serial.println("[APP] Setting up calendar...");
  if (debug == 1) Serial.println("[APP] Initializing NTP...");
  if (debug == 1) Serial.println("[APP] Waiting for NTP time sync...");
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    if (debug == 1) Serial.println("[APP] NTP sync failed");
  } else {
    if (debug == 1) Serial.println("[APP] NTP synced: " + String(asctime(&timeinfo)));
  }
  printMemoryUsage();
  if (debug == 1) Serial.println("[APP] Starting display setup...");
  setup_display();
  lv_obj_set_scrollbar_mode(lv_scr_act(), LV_SCROLLBAR_MODE_OFF);
  if (debug == 1) Serial.println("[APP] Display setup complete");
  // Apply initial darkness
  uint8_t gray = 255 - (g_ui_darkness * 255 / 100);
  lv_color_t bg_color = lv_color_make(gray, gray, gray);
  lv_color_t text_color = (g_ui_darkness > 50) ? lv_color_white() : lv_color_black();
  lv_obj_set_style_bg_color(lv_scr_act(), bg_color, 0);
  lv_obj_set_style_bg_opa(lv_scr_act(), LV_OPA_100, 0);
  lv_color_t original_bg_color = lv_obj_get_style_bg_color(lv_scr_act(), LV_PART_MAIN);
  lv_obj_set_style_bg_color(lv_scr_act(), lv_color_hex(0xFFFFFF), 0);
  lv_obj_t *splash_img = lv_img_create(lv_scr_act());
  lv_img_set_src(splash_img, &crontab);
  lv_obj_align(splash_img, LV_ALIGN_CENTER, 0, 0);
  lv_img_set_zoom(splash_img, 256);
  lv_obj_t *loading_img = lv_img_create(lv_scr_act());
  lv_img_set_src(loading_img, &loading);
  lv_obj_align_to(loading_img, splash_img, LV_ALIGN_OUT_BOTTOM_MID, -53, -60);
  lv_img_set_zoom(loading_img, 256);
  lv_anim_t a;
  lv_anim_init(&a);
  lv_anim_set_var(&a, loading_img);
  lv_anim_set_values(&a, 0, 3600);
  lv_anim_set_exec_cb(&a, (lv_anim_exec_xcb_t) lv_img_set_angle);
  lv_anim_set_time(&a, 2500); //time to rotate the clock
  lv_anim_set_repeat_count(&a, LV_ANIM_REPEAT_INFINITE);
  lv_anim_start(&a);
  loop_display();
  unsigned long start = millis();
  unsigned long last_tick = start;
  while (millis() - start < 3000) {
    unsigned long now = millis();
    if (now - last_tick >= 5) {
      lv_tick_inc(now - last_tick);
      last_tick = now;
    }
    lv_timer_handler();
  }
  if (debug == 1) Serial.println("[APP] Creating calendar...");
  calendar = lv_calendar_create(lv_scr_act());
  if (!calendar) {
    if (debug == 1) Serial.println("[APP] Error: Failed to create calendar");
    return;
  }
  lv_obj_set_size(calendar, 350, 350);
  lv_obj_align(calendar, LV_ALIGN_TOP_LEFT, 10, 60); // Restored original position
  showed_year = timeinfo.tm_year + 1900;
  showed_month = timeinfo.tm_mon + 1;
  lv_calendar_set_showed_date(calendar, showed_year, showed_month);
  lv_obj_add_event_cb(calendar, calendar_event_cb, LV_EVENT_VALUE_CHANGED, NULL);
  static lv_style_t style_highlight;
  lv_style_init(&style_highlight);
  lv_style_set_bg_color(&style_highlight, lv_color_hex(0xFF0000));
  lv_obj_add_style(calendar, &style_highlight, LV_PART_ITEMS | LV_STATE_CHECKED);
  static lv_style_t style_today;
  lv_style_init(&style_today);
  lv_style_set_text_color(&style_today, lv_color_hex(0x0000FF));
  lv_style_set_border_width(&style_today, 2);
  lv_style_set_border_color(&style_today, lv_color_hex(0x0000FF));
  lv_obj_add_style(calendar, &style_today, LV_PART_ITEMS | LV_STATE_USER_1);
  lv_obj_set_style_text_color(calendar, text_color, LV_PART_ITEMS | LV_STATE_DEFAULT);
  const char * day_names[7] = {"Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"};
  lv_calendar_set_day_names(calendar, day_names);
  rearrange_calendar_parts(calendar);
  if (debug == 1) Serial.println("[APP] Creating build version label...");
  build_version_label = lv_label_create(lv_scr_act());
  //Build version
  lv_label_set_text(build_version_label, ("V." + String(build_version)).c_str());
  lv_obj_set_style_text_font(build_version_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(build_version_label, text_color, 0);
  lv_obj_align(build_version_label, LV_ALIGN_BOTTOM_LEFT, 10, -10); // Moved to bottom left
  if (debug == 1) Serial.println("[APP] Creating month label...");
  month_label = lv_label_create(lv_scr_act());
  lv_obj_set_style_text_font(month_label, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(month_label, text_color, 0);
  lv_obj_align_to(month_label, calendar, LV_ALIGN_OUT_TOP_MID, -150, -5); // Restored original position
  if (debug == 1) Serial.println("[APP] Creating holiday label...");
  holiday_label = lv_label_create(lv_scr_act());
  lv_obj_set_style_text_font(holiday_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(holiday_label, text_color, 0);
  lv_obj_align(holiday_label, LV_ALIGN_TOP_MID, 30, 5);
  lv_label_set_long_mode(holiday_label, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(holiday_label, 300);
  if (debug == 1) Serial.println("[APP] Creating date-time label...");
  date_time_label = lv_label_create(lv_scr_act());
  lv_obj_set_style_text_font(date_time_label, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(date_time_label, text_color, 0);
  lv_obj_align(date_time_label, LV_ALIGN_TOP_RIGHT, -10, 10);
  updateDateTimeLabel();
  updateMonthLabel(calendar);
  updateHolidayLabel();
  update_today_highlight(calendar);
  if (debug == 1) Serial.println("[APP] Fetching bank holidays...");
  fetchBankHolidays();
  if (debug == 1) Serial.println("[APP] Fetching initial events...");
  fetchEvents();
  updateEventDisplay(calendar);
  if (debug == 1) Serial.println("[APP] Fetching initial weather...");
  fetchWeather();
  updateWeatherDisplay();
  if (debug == 1) Serial.println("[APP] Checking for firmware updates...");
  checkFirmwareUpdate();
  fetchBackgroundFilename();
  fetchAndSetBackgroundImage();
  button_bar = lv_obj_create(lv_scr_act());
  lv_obj_set_size(button_bar, 350, 40);
  lv_obj_align_to(button_bar, calendar, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
  lv_obj_set_flex_flow(button_bar, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(button_bar, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_set_style_bg_opa(button_bar, LV_OPA_TRANSP, 0);
  lv_obj_set_style_pad_all(button_bar, 0, 0);
  lv_obj_set_style_border_width(button_bar, 0, 0);
  lv_obj_set_scrollbar_mode(button_bar, LV_SCROLLBAR_MODE_OFF);
  lv_obj_t *prev_btn = lv_button_create(button_bar);
  lv_obj_add_event_cb(prev_btn, prev_month_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_set_size(prev_btn, 40, 40);
  lv_obj_set_style_bg_color(prev_btn, lv_color_hex(0x333333), 0);
  lv_obj_t *prev_label = lv_label_create(prev_btn);
  lv_label_set_text(prev_label, LV_SYMBOL_LEFT);
  lv_obj_center(prev_label);
  lv_obj_set_style_text_font(prev_label, &lv_font_montserrat_14, 0);
  lv_obj_t *settings_btn_obj = lv_button_create(button_bar);
  lv_obj_add_event_cb(settings_btn_obj, settings_btn_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_set_style_bg_color(settings_btn_obj, lv_color_hex(0x333333), 0);
  lv_obj_t *settings_label = lv_label_create(settings_btn_obj);
  lv_label_set_text(settings_label, LV_SYMBOL_SETTINGS);
  lv_obj_center(settings_label);
  lv_obj_set_style_text_font(settings_label, &lv_font_montserrat_14, 0);
  lv_obj_set_size(settings_btn_obj, 40, 40);
  lv_obj_t *next_btn = lv_button_create(button_bar);
  lv_obj_add_event_cb(next_btn, next_month_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_set_size(next_btn, 40, 40);
  lv_obj_set_style_bg_color(next_btn, lv_color_hex(0x333333), 0);
  lv_obj_t *next_label = lv_label_create(next_btn);
  lv_label_set_text(next_label, LV_SYMBOL_RIGHT);
  lv_obj_center(next_label);
  lv_obj_set_style_text_font(next_label, &lv_font_montserrat_14, 0);
  updateFirmwareButton();
  wifi_icon = lv_img_create(lv_scr_act());
  lv_img_set_src(wifi_icon, getWifiImage());
  lv_img_set_zoom(wifi_icon, 109);
  lv_obj_align(wifi_icon, LV_ALIGN_TOP_RIGHT, -60, -13);
  if (debug == 1) Serial.println("[APP] Created WiFi icon");
  if (debug == 1) Serial.println("[APP] Setup complete");
  printMemoryUsage();
  if (splash_img) {
    lv_obj_del(splash_img);
    lv_obj_del(loading_img);
    splash_img = nullptr;
  }
  lv_obj_set_style_bg_color(lv_scr_act(), original_bg_color, LV_PART_MAIN);
  lv_tick_inc(5);
}
void button_event_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] Refresh button clicked");
  lv_obj_t * btn = (lv_obj_t*)lv_event_get_target(e);
  lv_obj_t * label = lv_obj_get_child(btn, 0);
  static bool toggle = false;
  toggle = !toggle;
  lv_label_set_text(label, toggle ? LV_SYMBOL_REFRESH : LV_SYMBOL_REFRESH);
  fetchEvents();
  updateEventDisplay((lv_obj_t*)lv_event_get_user_data(e));
  fetchWeather();
  updateWeatherDisplay();
  updateMonthLabel((lv_obj_t*)lv_event_get_user_data(e));
}
void calendar_event_cb(lv_event_t * e) {
  unsigned long currentTime = millis();
  if (currentTime - lastEventTime < debounceDelay) {
    if (debug == 1) Serial.println("[APP] Event debounced");
    return;
  }
  lastEventTime = currentTime;
  if (debug == 1) Serial.println("[APP] Calendar date selected");
  if (!e) {
    if (debug == 1) Serial.println("[APP] Error: Event object is null");
    return;
  }
  lv_obj_t *target = (lv_obj_t*)lv_event_get_target(e);
  if (!target) {
    if (debug == 1) Serial.println("[APP] Error: Event target is null");
    return;
  }
  if (debug == 1) Serial.println("[APP] Retrieving pressed date...");
  lv_calendar_date_t date;
  if (!lv_calendar_get_pressed_date(calendar, &date)) {
    if (debug == 1) Serial.println("[APP] Failed to get pressed date");
    return;
  }
  char date_str[32];
  snprintf(date_str, sizeof(date_str), "%04d-%02d-%02d", date.year, date.month, date.day);
  if (debug == 1) Serial.println("[APP] Selected date: " + String(date_str));
  if (date.year < 1970 || date.year > 2030 || date.month < 1 || date.month > 12 || date.day < 1 || date.day > 31) {
    if (debug == 1) Serial.println("[APP] Invalid date selected: " + String(date_str));
    return;
  }
  updateMonthLabel(calendar);
  show_new_event_popup(&date);
}
void update_today_highlight(lv_obj_t *cal) {
  lv_obj_t *btnm = lv_calendar_get_btnmatrix(cal);
  if (!btnm) return;
  lv_btnmatrix_clear_btn_ctrl_all(btnm, LV_BTNMATRIX_CTRL_CUSTOM_1);
  time_t now;
  time(&now);
  struct tm *tm = localtime(&now);
  int cur_year = tm->tm_year + 1900;
  int cur_month = tm->tm_mon + 1;
  int cur_day = tm->tm_mday;
  const lv_calendar_date_t *showed = lv_calendar_get_showed_date(cal);
  if (showed->year == cur_year && showed->month == cur_month) {
    struct tm first_day = *tm;
    first_day.tm_mday = 1;
    mktime(&first_day);
    int first_wday = first_day.tm_wday;
    uint32_t btn_id = first_wday + (cur_day - 1) + 7; // Add 7 to ignore the header row offset
    if (btn_id < 42) {
      lv_btnmatrix_set_btn_ctrl(btnm, btn_id, LV_BTNMATRIX_CTRL_CUSTOM_1);
    }
  }
}
void fetchWeatherLocation() {
  if (debug == 1) Serial.println("[APP] Fetching weather location...");
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected, skipping weather location fetch");
    return;
  }
  HTTPClient http;
  String url = "https://crontech.uk/api.php?weatherLocation=" + apiCode;
  if (debug == 1) Serial.println("[APP] Fetching from URL: " + url);
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    if (debug == 1) Serial.println("[APP] Weather location response: " + payload);
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      if (debug == 1) Serial.println("[APP] Weather location JSON parsing failed: " + String(error.c_str()));
      http.end();
      return;
    }
    location = doc["city_name"].as<String>();
    lat = String(doc["latitude"].as<float>(), 6);
    lon = String(doc["longitude"].as<float>(), 6);
    preferences.begin("location", false);
    preferences.putString("location", location);
    preferences.putString("lat", lat);
    preferences.putString("lon", lon);
    preferences.end();
    if (debug == 1) Serial.println("[APP] Weather location fetched and saved: City=" + location + ", lat=" + lat + ", lon=" + lon);
  } else {
    if (debug == 1) Serial.println("[APP] Weather location HTTP request failed: " + String(httpCode));
  }
  http.end();
}
// Replace the existing timezone constants with a proper DST-aware string
// Remove these lines:
// const long gmtOffset_sec = 0;
// const int daylightOffset_sec = 3600;

// Updated initTime() function with correct configTime usage and TZ setting for automatic DST
void initTime() {
  if (debug == 1) Serial.println("[APP] Initializing time with automatic DST support...");
  // First, sync time in UTC
  configTime(0, 0, ntpServer);
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo)) {
    if (debug == 1) Serial.println("[APP] Failed to obtain initial time from NTP");
    return;
  }
  if (debug == 1) Serial.println("[APP] Initial UTC time obtained from NTP");
  // Now set the UK timezone string for automatic GMT/BST handling
  setenv("TZ", "GMT0BST,M3.5.0/1,M10.5.0", 1);
  tzset();
  // Verify local time after TZ set
  if (getLocalTime(&timeinfo)) {
    if (debug == 1) Serial.println("[APP] Local time after TZ set: " + String(timeinfo.tm_year + 1900) + "-" + 
                                   String(timeinfo.tm_mon + 1) + "-" + String(timeinfo.tm_mday) + " " + 
                                   String(timeinfo.tm_hour) + ":" + String(timeinfo.tm_min) + ":" + String(timeinfo.tm_sec));
  } else {
    if (debug == 1) Serial.println("[APP] Failed to obtain local time after TZ set");
  }
}
// Modified setup() function: Add the initTime() call after successful WiFi connection
void setup() {
  Serial.begin(115200);
  delay(1000);
  if (debug == 1) Serial.printf("[APP] Free heap at start: %d bytes, Free PSRAM: %d bytes\n",
                heap_caps_get_free_size(MALLOC_CAP_8BIT),
                heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  preferences.begin("firmware", false);
  currentFirmwareVersion = preferences.getString("version", "1.0.0");
  preferences.end();
  preferences.begin("ui", false);
  g_ui_darkness = preferences.getInt("ui_darkness", 0);
  preferences.end();
  preferences.begin("wifi", false);
  ssid = preferences.getString("ssid", "");
  password = preferences.getString("password", "");
  preferences.end();
  if (ssid == "" || password == "") {
    if (debug == 1) Serial.println("[APP] No WiFi credentials found, showing WiFi setup screen");
    setup_display();
    show_wifi_setup_screen();
  } else {
    WiFi.begin(ssid.c_str(), password.c_str());
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
      delay(500);
      if (debug == 1) Serial.print(".");
      attempts++;
    }
    if (WiFi.status() == WL_CONNECTED) {
      if (debug == 1) Serial.println("[APP] WiFi connected! IP: " + WiFi.localIP().toString());
      // NEW: Initialize time with automatic DST after WiFi connects
      initTime();
      preferences.begin("api", false);
      apiCode = preferences.getString("apiCode", "");
      preferences.end();
      if (apiCode == "") {
        setup_display();
        show_api_code_screen();
      } else {
        fetchParcelBoxCredentials();
        fetchWeatherLocation(); // Added
        preferences.begin("location", false);
        location = preferences.getString("location", "");
        lat = preferences.getString("lat", "");
        lon = preferences.getString("lon", "");
        temp_adjust = preferences.getInt("temp_adjust", 0);
        preferences.end();
        preferences.begin("cloudapps", false);
        username = preferences.getString("username", "");
        device_id = preferences.getString("device_id", "");
        preferences.end();
        if (location == "") {
          setup_display();
          show_location_screen();
        } else {
			setup_calendar();
			fetchBankHolidays();
			updateHolidayLabel();
			lastHolidayUpdate = millis();
		  
        }
      }
    } else {
      setup_display();
      show_wifi_setup_screen();
    }
  }
}



///////////////////////////////////////////////////////////////////////

void loop() {
  if (!is_ota_updating) {
    // Normal LVGL update cycle
    loop_display();
    lv_tick_inc(5);
    
    // Scheduled restart logic: Trigger at midnight on Sundays or the 1st of each month
    static int prev_sec = -1;  // Track previous second to avoid multi-trigger at exact midnight
    time_t now_t;
    time(&now_t);
    struct tm *timeinfo = localtime(&now_t);
    if (timeinfo->tm_hour == 0 && timeinfo->tm_min == 0 && timeinfo->tm_sec == 0 &&
        (timeinfo->tm_wday == 0 || timeinfo->tm_mday == 1) &&
        prev_sec != 0) {  // Ensure it's the transition to midnight
      if (debug == 1) {
        String msg = String("[APP] Scheduled restart triggered: ") + (timeinfo->tm_wday == 0 ? "Sunday midnight" : "1st of month midnight");
        Serial.println(msg);
      }
      ESP.restart();
    }
    prev_sec = timeinfo->tm_sec;  // Update tracker
    
    delay(5);
  } else {
    // OTA mode: Skip LVGL, handle OTA periodically (though blocking in this case)
    delay(100); // Longer delay to reduce CPU usage during OTA
    // Since updateFirmware is blocking and ends with restart, no further handling needed here
    // For non-blocking OTA, add: handle_ota_progress();
    // Check if OTA completed (not applicable in blocking mode, but placeholder)
    // if (ota_update_completed()) {
    //   is_ota_updating = false;
    //   // Re-enable display if quiesced
    //   // display_power_up();
    //   reset_update_ui(); // Reset UI if needed
    //   if (debug == 1) Serial.println("[APP] OTA completed; resuming screen refresh.");
    // }
  }
  unsigned long currentTime = millis();
  if (currentTime - lastRefreshTime >= refreshInterval && calendar && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    fetchEvents();
    updateEventDisplay(calendar);
    updateMonthLabel(calendar);
    lastRefreshTime = currentTime;
  }
  if (currentTime - lastFirmwareCheck >= firmwareCheckInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    checkFirmwareUpdate();
    updateFirmwareButton();
    lastFirmwareCheck = currentTime;
  }
  if (currentTime - lastNotificationCheck >= notificationInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    fetchNotifications();
    lastNotificationCheck = currentTime;
  }
  if (currentTime - lastWifiUpdate >= wifiUpdateInterval && !is_ota_updating) {
    if (wifi_icon) {
      lv_img_set_src(wifi_icon, getWifiImage());
    }
    lastWifiUpdate = currentTime;
  }
  if (currentTime - lastCredentialsCheck >= credentialsInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    fetchParcelBoxCredentials();
    lastCredentialsCheck = currentTime;
  }
  if (currentTime - lastWeatherLocationCheck >= weatherLocationInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    fetchWeatherLocation();
    lastWeatherLocationCheck = currentTime;
  }
  if (currentTime - lastTimeLabelUpdate >= timeLabelUpdateInterval && !is_ota_updating) {
    updateDateTimeLabel();
    lastTimeLabelUpdate = currentTime;
  }
  if (currentTime - lastDateTimeUpdate >= dateTimeUpdateInterval && !is_ota_updating) {
    time_t now;
    time(&now);
    for (int i = 0; i < numEvents; i++) {
      time_t reminder_time = events[i].start_time - events[i].remind_before * 60;
      if (now >= reminder_time && now < events[i].start_time && !events[i].notified && events[i].reminder_count < 3) {
        if (events[i].reminder_count == 0 || (now - events[i].last_reminder_time >= 300)) {
          show_event_details(i, true);
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
  if (currentTime - lastWeatherUpdate >= weatherUpdateInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    fetchWeather();
    updateWeatherDisplay();
    lastWeatherUpdate = currentTime;
  }
  if (currentTime - lastBackgroundUpdate >= backgroundUpdateInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
    fetchBackgroundFilename();
    fetchAndSetBackgroundImage();
    lastBackgroundUpdate = currentTime;
  }
  if (currentTime - lastHolidayUpdate >= holidayUpdateInterval && WiFi.status() == WL_CONNECTED && !is_ota_updating) {
  fetchBankHolidays();
  updateHolidayLabel();
  lastHolidayUpdate = currentTime;
}
}

void show_event_details(int index, bool isReminder) {
  if (event_details_popup) {
    return; // Avoid multiple popups
  }
  if (index < 0 || index >= numEvents) {
    if (debug == 1) Serial.println("[APP] Invalid event index for details popup");
    return;
  }
  event_details_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(event_details_popup, 700, 420);
  lv_obj_align(event_details_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(event_details_popup, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_color(event_details_popup, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_border_width(event_details_popup, 2, 0);
  // Event details container
  lv_obj_t *event_info_cont = lv_obj_create(event_details_popup);
  lv_obj_set_size(event_info_cont, 680, 150);
  lv_obj_align(event_info_cont, LV_ALIGN_TOP_MID, 0, 10);
  lv_obj_set_style_bg_color(event_info_cont, lv_color_hex(0xF0F0F0), 0);
  lv_obj_set_style_bg_opa(event_info_cont, LV_OPA_COVER, 0);
  lv_obj_set_style_border_width(event_info_cont, 0, 0);
  lv_obj_set_style_pad_all(event_info_cont, 10, 0);
  lv_obj_set_style_pad_row(event_info_cont, 5, 0);
  lv_obj_set_scrollbar_mode(event_info_cont, LV_SCROLLBAR_MODE_OFF);
  // Title
  lv_obj_t *title_label = lv_label_create(event_info_cont);
  lv_label_set_text(title_label, events[index].summary.c_str());
  lv_obj_align(title_label, LV_ALIGN_TOP_MID, 0, 0);
  lv_obj_set_style_text_font(title_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(title_label, lv_color_hex(0x000000), 0);
  // Description
  lv_obj_t *desc_label = lv_label_create(event_info_cont);
  lv_label_set_text(desc_label, events[index].description.c_str());
  lv_label_set_long_mode(desc_label, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(desc_label, 650);
  lv_obj_align_to(desc_label, title_label, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
  lv_obj_set_style_text_font(desc_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(desc_label, lv_color_hex(0x000000), 0);
  // Start
  lv_obj_t *start_label = lv_label_create(event_info_cont);
  lv_label_set_text(start_label, ("Start: " + events[index].start).c_str());
  lv_obj_align_to(start_label, desc_label, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
  lv_obj_set_style_text_font(start_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(start_label, lv_color_hex(0x000000), 0);
  // End
  lv_obj_t *end_label = lv_label_create(event_info_cont);
  lv_label_set_text(end_label, ("End: " + events[index].end).c_str());
  lv_obj_align_to(end_label, start_label, LV_ALIGN_OUT_BOTTOM_MID, 0, 5);
  lv_obj_set_style_text_font(end_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(end_label, lv_color_hex(0x000000), 0);
  // Determine the event's start date
  struct tm event_start_tm = {0};
  String event_start_str = events[index].start; // e.g., "2025-10-25" or "2025-10-25 14:00"
  if (!event_start_str.isEmpty()) {
    // Parse the date (assuming format "YYYY-MM-DD" or "YYYY-MM-DD HH:MM")
    if (strptime(event_start_str.c_str(), "%Y-%m-%d %H:%M", &event_start_tm) == NULL) {
      // Try parsing without time if the above fails
      strptime(event_start_str.c_str(), "%Y-%m-%d", &event_start_tm);
    }
  }
  event_start_tm.tm_hour = 0;
  event_start_tm.tm_min = 0;
  event_start_tm.tm_sec = 0;
  event_start_tm.tm_isdst = -1;
  time_t event_start_day = mktime(&event_start_tm);
  // Parse end date similarly to check for single-day event
  struct tm event_end_tm = {0};
  String event_end_str = events[index].end;
  if (!event_end_str.isEmpty()) {
    if (strptime(event_end_str.c_str(), "%Y-%m-%d %H:%M", &event_end_tm) == NULL) {
      strptime(event_end_str.c_str(), "%Y-%m-%d", &event_end_tm);
    }
  }
  event_end_tm.tm_hour = 0;
  event_end_tm.tm_min = 0;
  event_end_tm.tm_sec = 0;
  event_end_tm.tm_isdst = -1;
  time_t event_end_day = mktime(&event_end_tm);
  bool is_single_day = (event_start_day == event_end_day);
  // Calculate number of event days
  long full_duration_sec = (long)events[index].end_time - (long)events[index].start_time;
  int num_event_days_full = (full_duration_sec / 86400L) + 1;
  // Get today's start
  time_t now_t;
  time(&now_t);
  struct tm today_tm = {0};
  getLocalTime(&today_tm);
  today_tm.tm_hour = 0;
  today_tm.tm_min = 0;
  today_tm.tm_sec = 0;
  today_tm.tm_isdst = -1;
  time_t today_start = mktime(&today_tm);
  // Determine display parameters
  time_t display_start_day;
  int num_display_days;
  if (now_t > events[index].start_time) {
    // Ongoing or past
    long remaining_sec = (long)events[index].end_time - now_t;
    if (remaining_sec >= 0) {
      num_display_days = (remaining_sec / 86400L) + 1;
      display_start_day = today_start;
    } else {
      num_display_days = 0;
      display_start_day = 0; // Irrelevant
    }
  } else {
    // Future
    num_display_days = num_event_days_full;
    display_start_day = event_start_day;
  }
  // Weather section: Single-day vs Multi-day layout
  if (is_single_day && num_display_days >= 1) {
    // Single-day wider layout
    lv_obj_t *weather_cont = lv_obj_create(event_details_popup);
    lv_obj_set_size(weather_cont, 680, 120);
    lv_obj_align_to(weather_cont, event_info_cont, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
    lv_obj_set_flex_flow(weather_cont, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(weather_cont, LV_FLEX_ALIGN_SPACE_BETWEEN, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_set_style_bg_color(weather_cont, lv_color_white(), 0);
    lv_obj_set_style_bg_opa(weather_cont, LV_OPA_COVER, 0);
    lv_obj_set_style_border_width(weather_cont, 0, 0);
    lv_obj_set_style_pad_all(weather_cont, 5, 0);
    lv_obj_set_style_pad_column(weather_cont, 10, 0);
    lv_obj_set_scrollbar_mode(weather_cont, LV_SCROLLBAR_MODE_OFF);

    // Calculate f_index for the single event day
    time_t event_day = event_start_day;
    int f_index = (int)((event_day - today_start) / 86400);
    bool has_forecast = (f_index >= 0 && f_index < 14);

    // Left: Weather icon and temperatures (UPDATED: Added night/min temp)
    lv_obj_t *left_cont = lv_obj_create(weather_cont);
    lv_obj_set_size(left_cont, 200, 120);
    lv_obj_set_flex_flow(left_cont, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_flex_align(left_cont, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_set_style_bg_opa(left_cont, LV_OPA_TRANSP, 0);
    lv_obj_set_style_pad_all(left_cont, 5, 0);

    // Weather image
    lv_obj_t *weather_img = lv_img_create(left_cont);
    if (has_forecast) {
      lv_img_set_src(weather_img, getWeatherImage(forecast[f_index].weather_code));
    } else {
      lv_img_set_src(weather_img, getWeatherImage(999)); // Unknown
    }
    lv_img_set_zoom(weather_img, 150); // Slightly larger for single-day
    lv_obj_align(weather_img, LV_ALIGN_CENTER, 0, -40);  // UPDATED: Adjusted for stacked temps

    // Max temperature label
    lv_obj_t *temp_max_label = lv_label_create(left_cont);
    if (has_forecast) {
      lv_label_set_text(temp_max_label, (String(forecast[f_index].temp_max + temp_adjust, 1) + "°C").c_str());
    } else {
      lv_label_set_text(temp_max_label, "N/A");
    }
    lv_obj_set_style_text_font(temp_max_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(temp_max_label, lv_color_hex(0x000000), 0);
    lv_obj_align(temp_max_label, LV_ALIGN_CENTER, 0, 0);  // Centered horizontally

    // NEW: Min (night) temperature label below max
    lv_obj_t *temp_min_label = lv_label_create(left_cont);
    if (has_forecast) {
      lv_label_set_text(temp_min_label, (String(forecast[f_index].temp_min + temp_adjust, 1) + "°C").c_str());
      lv_obj_set_style_text_font(temp_min_label, &lv_font_montserrat_14, 0);  // Slightly smaller font for min
      lv_obj_set_style_text_color(temp_min_label, lv_color_hex(0x666666), 0);  // Gray for secondary info
    } else {
      lv_label_set_text(temp_min_label, "N/A");
    }
    lv_obj_align_to(temp_min_label, temp_max_label, LV_ALIGN_OUT_BOTTOM_MID, 0, -5);  // Stacked below max

    // Right: Conditions (description, humidity, feel like, precip, wind)
    lv_obj_t *right_cont = lv_obj_create(weather_cont);
    lv_obj_set_size(right_cont, 460, 120);
    lv_obj_set_flex_flow(right_cont, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_flex_align(right_cont, LV_FLEX_ALIGN_SPACE_AROUND, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER);
    lv_obj_set_style_bg_opa(right_cont, LV_OPA_TRANSP, 0);
    lv_obj_set_style_pad_all(right_cont, 5, 0);
    lv_obj_set_style_pad_row(right_cont, 2, 0);

    // Description
    lv_obj_t *desc_weather_label = lv_label_create(right_cont);
    String desc = has_forecast ? getWeatherDescription(forecast[f_index].weather_code) : "Unknown";
    lv_label_set_text(desc_weather_label, desc.c_str());
    lv_obj_set_style_text_font(desc_weather_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(desc_weather_label, lv_color_hex(0x000000), 0);
    lv_label_set_long_mode(desc_weather_label, LV_LABEL_LONG_WRAP);
    lv_obj_set_width(desc_weather_label, 200);

    // Humidity
    lv_obj_t *hum_label = lv_label_create(right_cont);
    String hum_text = has_forecast ? ("Humidity: " + String((int)forecast[f_index].humidity_mean) + "%") : "Humidity: N/A";
    lv_label_set_text(hum_label, hum_text.c_str());
    lv_obj_set_style_text_font(hum_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(hum_label, lv_color_hex(0x000000), 0);

    // Feel like (apparent temp)
    lv_obj_t *feel_label = lv_label_create(right_cont);
    String feel_text = has_forecast ? ("Feels like: " + String(forecast[f_index].apparent_max + temp_adjust, 1) + "°C") : "Feels like: N/A";
    lv_label_set_text(feel_label, feel_text.c_str());
    lv_obj_set_style_text_font(feel_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(feel_label, lv_color_hex(0x000000), 0);

    // Precipitation
    lv_obj_t *precip_label = lv_label_create(right_cont);
    String precip_text = has_forecast ? ("Precip: " + String(forecast[f_index].precip_sum, 1) + " mm") : "Precip: N/A";
    lv_label_set_text(precip_label, precip_text.c_str());
    lv_obj_set_style_text_font(precip_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(precip_label, lv_color_hex(0x000000), 0);

    // Wind
    lv_obj_t *wind_label = lv_label_create(right_cont);
    String wind_text = has_forecast ? ("Wind: " + String(forecast[f_index].wind_max, 0) + " km/h") : "Wind: N/A";
    lv_label_set_text(wind_label, wind_text.c_str());
    lv_obj_set_style_text_font(wind_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(wind_label, lv_color_hex(0x000000), 0);
  } else {
    // Multi-day forecast container (existing logic)
    lv_obj_t *forecast_cont = lv_obj_create(event_details_popup);
    lv_obj_set_size(forecast_cont, 680, 100);
    lv_obj_align_to(forecast_cont, event_info_cont, LV_ALIGN_OUT_BOTTOM_MID, 0, 10);
    lv_obj_set_flex_flow(forecast_cont, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(forecast_cont, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_set_style_bg_opa(forecast_cont, LV_OPA_TRANSP, 0);
    lv_obj_set_style_border_width(forecast_cont, 0, 0);
    lv_obj_set_style_pad_all(forecast_cont, 5, 0);
    lv_obj_set_style_pad_column(forecast_cont, 2, 0);
    lv_obj_set_scrollbar_mode(forecast_cont, LV_SCROLLBAR_MODE_OFF);
    // Loop for display days
    int loop_days = (num_display_days > 0) ? min(num_display_days, 14) : 0;
    for (int i = 0; i < loop_days; i++) {
      time_t cur_day = display_start_day + (time_t)i * 86400;
      int f_index = (int)((cur_day - today_start) / 86400);
      lv_obj_t *day_cont = lv_obj_create(forecast_cont);
      lv_obj_set_size(day_cont, 90, 90);
      lv_obj_set_flex_flow(day_cont, LV_FLEX_FLOW_COLUMN);
      lv_obj_set_flex_align(day_cont, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
      lv_obj_set_style_bg_color(day_cont, lv_color_hex(0xF0F0F0), 0);
      lv_obj_set_style_bg_opa(day_cont, LV_OPA_90, 0);
      lv_obj_set_style_radius(day_cont, 10, 0);
      lv_obj_set_style_shadow_color(day_cont, lv_color_hex(0x000000), 0);
      lv_obj_set_style_shadow_width(day_cont, 10, 0);
      lv_obj_set_style_shadow_opa(day_cont, LV_OPA_20, 0);
      lv_obj_set_style_border_width(day_cont, 0, 0);
      lv_obj_set_style_pad_all(day_cont, 2, 0);
      lv_obj_set_style_pad_row(day_cont, -20, 0);
      // Date label
      char date_buf[20];
      struct tm *d_tm = localtime(&cur_day);
      strftime(date_buf, sizeof(date_buf), "%Y-%m-%d", d_tm);
      lv_obj_t *date_label = lv_label_create(day_cont);
      lv_label_set_text(date_label, date_buf);
      lv_obj_set_style_text_font(date_label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(date_label, lv_color_hex(0x000000), 0);
      // Check if forecast data is available for this day
      bool has_forecast = (f_index >= 0 && f_index < 14);
      if (has_forecast) {
        // Weather image
        lv_obj_t *forecast_img = lv_img_create(day_cont);
        lv_img_set_src(forecast_img, getWeatherImage(forecast[f_index].weather_code));
        lv_img_set_zoom(forecast_img, 102); // 50% of 204 for consistency
        // Temperature
        lv_obj_t *temp_l = lv_label_create(day_cont);
        lv_label_set_text(temp_l, (String(forecast[f_index].temp_max + temp_adjust, 1) + "°C").c_str());
        lv_obj_set_style_text_font(temp_l, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_color(temp_l, lv_color_hex(0x000000), 0);
      } else {
        // Unknown weather image
        lv_obj_t *forecast_img = lv_img_create(day_cont);
        lv_img_set_src(forecast_img, getWeatherImage(999)); // Will default to Unknown
        lv_img_set_zoom(forecast_img, 102); // Consistent with other images
        // Placeholder temperature
        lv_obj_t *temp_l = lv_label_create(day_cont);
        lv_label_set_text(temp_l, "N/A");
        lv_obj_set_style_text_font(temp_l, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_color(temp_l, lv_color_hex(0x000000), 0);
      }
    }
  }
  // Snooze button (conditionally shown)
  lv_obj_t *snooze_btn = lv_button_create(event_details_popup);
  lv_obj_align(snooze_btn, LV_ALIGN_BOTTOM_LEFT, 20, -20);
  lv_obj_set_size(snooze_btn, 120, 40);
  lv_obj_set_style_bg_color(snooze_btn, lv_color_hex(0x00FF00), 0);
  lv_obj_add_event_cb(snooze_btn, snooze_reminder_cb, LV_EVENT_CLICKED, (void*)(intptr_t)index);
  lv_obj_t *snooze_label = lv_label_create(snooze_btn);
  lv_label_set_text(snooze_label, "Snooze");
  lv_obj_center(snooze_label);
  lv_obj_set_style_text_font(snooze_label, &lv_font_montserrat_14, 0);
  if (!isReminder) {
    lv_obj_add_flag(snooze_btn, LV_OBJ_FLAG_HIDDEN);
  }
  // Close/Cancel button
  lv_obj_t *close_btn = lv_button_create(event_details_popup);
  lv_obj_align(close_btn, LV_ALIGN_BOTTOM_RIGHT, -20, -20);
  lv_obj_set_size(close_btn, 120, 40);
  lv_obj_set_style_bg_color(close_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_t *close_label = lv_label_create(close_btn);
  if (isReminder) {
    lv_label_set_text(close_label, "Cancel");
    lv_obj_add_event_cb(close_btn, cancel_reminder_cb, LV_EVENT_CLICKED, (void*)(intptr_t)index);
  } else {
    lv_label_set_text(close_label, "Close");
    lv_obj_add_event_cb(close_btn, close_event_details_cb, LV_EVENT_CLICKED, NULL);
  }
  lv_obj_center(close_label);
  lv_obj_set_style_text_font(close_label, &lv_font_montserrat_14, 0);
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
  if (debug == 1) Serial.printf("[DEBUG] Cancel reminder for event %d: set notified to true\n", index);
  // Generate short key using hash
  String full_id = events[index].start + "_" + events[index].summary;
  unsigned long hash = hashString(full_id);
  String base_key = "e" + String(hash % 100000000); // "e" + up to 8 digits (9 chars total)
  // Save to preferences
  preferences.begin("event_states", false);
  preferences.putInt((base_key + "c").c_str(), events[index].reminder_count);
  preferences.putLong((base_key + "l").c_str(), events[index].last_reminder_time);
  preferences.putBool((base_key + "n").c_str(), events[index].notified);
  preferences.end();
  close_event_details_cb(e);
}
unsigned long hashString(const String& str) {
  unsigned long hash = 5381;
  for (size_t i = 0; i < str.length(); ++i) {
    int c = str[i];
    hash = ((hash << 5) + hash) + c; /* hash * 33 + c */
  }
  return hash;
}
void addEvent(JsonObject eventObj) {
  if (numEvents >= 300) {
    if (debug == 1) Serial.println("[APP] Event limit reached (300)");
    return;
  }
  events[numEvents].summary = eventObj["summary"].as<String>();
  events[numEvents].start = eventObj["start"].as<String>();
  events[numEvents].end = eventObj["end"].as<String>();
  events[numEvents].description = eventObj["description"].as<String>();
  String remind_before_str = eventObj["remind_before"].as<String>();
  if (events[numEvents].start.isEmpty() || events[numEvents].end.isEmpty()) {
    if (debug == 1) Serial.println("[APP] Event " + String(numEvents) + " has empty start/end, skipping");
    return;
  }
  events[numEvents].isAllDay = (events[numEvents].start.endsWith("00:00:00") &&
                               (events[numEvents].end.endsWith("23:59:00") ||
                                events[numEvents].end.endsWith("23:59:59")));
  if (debug == 1) {
    Serial.println("[APP] Event " + String(numEvents) + ":");
    Serial.println(" Summary: " + events[numEvents].summary);
    Serial.println(" Start: " + events[numEvents].start);
    Serial.println(" End: " + events[numEvents].end);
    Serial.println(" Description: " + events[numEvents].description);
    Serial.println(" Remind before: " + remind_before_str);
    Serial.println(" AllDay: " + String(events[numEvents].isAllDay ? "Yes" : "No"));
  }
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
    if (debug == 1) Serial.println("[APP] Failed to parse dates for event " + String(numEvents) + ": " + startDate + " to " + endDate);
    return;
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
  start_tm.tm_isdst = -1; // Add this line to enable automatic DST detection
  struct tm end_tm = {0};
  end_tm.tm_year = endYear - 1900;
  end_tm.tm_mon = endMonth - 1;
  end_tm.tm_mday = endDay;
  end_tm.tm_hour = endHour;
  end_tm.tm_min = endMin;
  end_tm.tm_sec = endSec;
  end_tm.tm_isdst = -1; // Add this line to enable automatic DST detection
  time_t start_time = mktime(&start_tm);
  time_t end_time = mktime(&end_tm);
  if (start_time == -1 || end_time == -1) {
    if (debug == 1) Serial.println("[APP] Invalid time conversion for event " + String(numEvents));
    return;
  }
  if (debug == 1) Serial.printf("[DEBUG] Event %d start Unix timestamp: %ld\n", numEvents, start_time);
  struct tm *local_start_tm = localtime(&start_time);
  char local_start_buf[32];
  strftime(local_start_buf, sizeof(local_start_buf), "%Y-%m-%d %H:%M:%S", local_start_tm);
  if (debug == 1) Serial.println("[DEBUG] Event " + String(numEvents) + " local start: " + String(local_start_buf));
  if (debug == 1) Serial.printf("[DEBUG] Event %d end Unix timestamp: %ld\n", numEvents, end_time);
  struct tm *local_end_tm = localtime(&end_time);
  char local_end_buf[32];
  strftime(local_end_buf, sizeof(local_end_buf), "%Y-%m-%d %H:%M:%S", local_end_tm);
  if (debug == 1) Serial.println("[DEBUG] Event " + String(numEvents) + " local end: " + String(local_end_buf));
  events[numEvents].start_time = start_time;
  events[numEvents].end_time = end_time;
  // Generate short key using hash
  String full_id = events[numEvents].start + "_" + events[numEvents].summary;
  unsigned long hash = hashString(full_id);
  String base_key = "e" + String(hash % 100000000); // "e" + up to 8 digits (9 chars total)
  // Load persisted states
  preferences.begin("event_states", false);
  events[numEvents].reminder_count = preferences.getInt((base_key + "c").c_str(), 0);
  events[numEvents].last_reminder_time = (time_t)preferences.getLong((base_key + "l").c_str(), 0);
  events[numEvents].notified = preferences.getBool((base_key + "n").c_str(), false);
  preferences.end();
  if (start_time > end_time) {
    if (debug == 1) Serial.println("[APP] Invalid date range: start > end, swapping");
    time_t temp = start_time;
    start_time = end_time;
    end_time = temp;
  }
  time_t current_time = start_time;
  while (current_time <= end_time && numEventDates < 1000) {
    struct tm *tm = localtime(&current_time);
    if (tm->tm_year + 1900 < 1970 || tm->tm_year + 1900 > 2030) {
      if (debug == 1) Serial.println("[APP] Invalid highlight date: " + String(tm->tm_year + 1900) + "-" +
                     String(tm->tm_mon + 1) + "-" + String(tm->tm_mday) + ", skipping");
      current_time += 86400;
      continue;
    }
    eventDates[numEventDates].year = tm->tm_year + 1900;
    eventDates[numEventDates].month = tm->tm_mon + 1;
    eventDates[numEventDates].day = tm->tm_mday;
    if (debug == 1) Serial.println("[APP] Added highlight date: " +
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
  if (debug == 1) Serial.println("[APP] Event isToday: " + String(events[numEvents].isToday ? "Yes" : "No"));
  numEvents++;
}
void show_event_details_cb(lv_event_t *e) {
  lv_obj_t *target = (lv_obj_t*)lv_event_get_target(e);
  int index = (intptr_t)lv_obj_get_user_data(target);
  show_event_details(index, false);
}
void save_settings_cb(lv_event_t *e) {
  if (debug == 1) Serial.println("[APP] Settings save button clicked");
  SettingsUI *sui = (SettingsUI*)lv_event_get_user_data(e);
  if (!sui) {
    if (debug == 1) Serial.println("[APP] Error: SettingsUI structure is null");
    return;
  }
  String new_location = String(lv_textarea_get_text(sui->location_ta));
  username = String(lv_textarea_get_text(sui->username_ta));
  device_id = String(lv_textarea_get_text(sui->device_id_ta));
  temp_adjust = String(lv_textarea_get_text(sui->temp_adjust_ta)).toInt();
  // Save parcelBox credentials
  if (!username.isEmpty() && !device_id.isEmpty()) {
    HTTPClient http;
    String url = "https://crontech.uk/api.php?parcelBox=" + URLEncode(apiCode) + "&parcel_box_username=" + URLEncode(username) + "&parcel_box_id=" + URLEncode(device_id);
    http.begin(url);
    int httpCode = http.GET();
    if (httpCode == HTTP_CODE_OK) {
      String payload = http.getString();
      JsonDocument doc;
      DeserializationError error = deserializeJson(doc, payload);
      if (!error) {
        username = doc["username"].as<String>();
        device_id = doc["parcel_box_id"].as<String>();
        preferences.begin("cloudapps", false);
        preferences.putString("username", username);
        preferences.putString("device_id", device_id);
        preferences.end();
        if (debug == 1) Serial.println("[APP] ParcelBox credentials saved: Username=" + username + ", Device ID=" + device_id);
      } else {
        if (debug == 1) Serial.println("[APP] ParcelBox JSON parsing failed: " + String(error.c_str()));
      }
    } else {
      if (debug == 1) Serial.println("[APP] ParcelBox update failed: " + String(httpCode));
    }
    http.end();
  }
  // Save weather location
  if (!new_location.isEmpty()) {
    HTTPClient http;
    String url = "https://crontech.uk/api.php?weatherLocation=" + URLEncode(apiCode) + "&city_name=" + URLEncode(new_location);
    http.begin(url);
    int httpCode = http.GET();
    if (httpCode == HTTP_CODE_OK) {
      String payload = http.getString();
      JsonDocument doc;
      DeserializationError error = deserializeJson(doc, payload);
      if (!error) {
        location = doc["city_name"].as<String>();
        lat = String(doc["latitude"].as<float>(), 6);
        lon = String(doc["longitude"].as<float>(), 6);
        preferences.begin("location", false);
        preferences.putString("location", location);
        preferences.putString("lat", lat);
        preferences.putString("lon", lon);
        preferences.end();
        if (debug == 1) Serial.println("[APP] Weather location saved: " + location + ", lat: " + lat + ", lon: " + lon);
      } else {
        if (debug == 1) Serial.println("[APP] Weather location JSON parsing failed: " + String(error.c_str()));
      }
    } else {
      if (debug == 1) Serial.println("[APP] Weather location update failed: " + String(httpCode));
    }
    http.end();
  }
  preferences.begin("location", false);
  preferences.putInt("temp_adjust", temp_adjust);
  preferences.end();
  fetchWeather();
  updateWeatherDisplay();
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  delete sui;
}
void device_id_preview_cb(lv_event_t *e) {
  lv_obj_t *ta = (lv_obj_t*)lv_event_get_target(e);
  lv_obj_t *label = (lv_obj_t*)lv_event_get_user_data(e);
  const char *text = lv_textarea_get_text(ta);
  lv_label_set_text(label, text);
}
void fetchParcelBoxCredentials() {
  if (debug == 1) Serial.println("[APP] Fetching parcelbox credentials...");
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected, skipping parcelbox credentials fetch");
    return;
  }
  HTTPClient http;
  String url = "https://crontech.uk/api.php?parcelBox=" + apiCode;
  if (debug == 1) Serial.println("[APP] Fetching from URL: " + url);
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    if (debug == 1) Serial.println("[APP] Parcelbox response: " + payload);
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      if (debug == 1) Serial.println("[APP] Parcelbox JSON parsing failed: " + String(error.c_str()));
      http.end();
      return;
    }
    username = doc["username"].as<String>();
    device_id = doc["parcel_box_id"].as<String>();
    preferences.begin("cloudapps", false);
    preferences.putString("username", username);
    preferences.putString("device_id", device_id);
    preferences.end();
    if (debug == 1) Serial.println("[APP] Parcelbox credentials preloaded and saved: Username=" + username + ", Device ID=" + device_id);
  } else {
    if (debug == 1) Serial.println("[APP] Parcelbox HTTP request failed: " + String(httpCode));
  }
  http.end();
}
// New function to fetch bank holidays
void fetchBankHolidays() {
  if (debug == 1) Serial.println("[APP] Fetching bank holidays...");
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected, skipping bank holidays fetch");
    return;
  }
  HTTPClient http;
  String url = "https://www.gov.uk/bank-holidays.json";
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (error) {
      if (debug == 1) Serial.println("[APP] Bank holidays JSON parsing failed: " + String(error.c_str()));
      http.end();
      return;
    }
    JsonArray eventsArray = doc["england-and-wales"]["events"];
    numHolidays = 0;
    for (JsonObject evt : eventsArray) {
      if (numHolidays >= 100) break;
      String date = evt["date"].as<String>();
      String title = evt["title"].as<String>();
      int y, m, d;
      if (sscanf(date.c_str(), "%d-%d-%d", &y, &m, &d) == 3) {
        holidays[numHolidays].year = y;
        holidays[numHolidays].month = m;
        holidays[numHolidays].day = d;
        holidays[numHolidays].title = title;
        // Add to eventDates for highlighting if not already present
        bool exists = false;
        for (int j = 0; j < numEventDates; j++) {
          if (eventDates[j].year == y && eventDates[j].month == m && eventDates[j].day == d) {
            exists = true;
            break;
          }
        }
        if (!exists && numEventDates < 1000) {
          eventDates[numEventDates].year = y;
          eventDates[numEventDates].month = m;
          eventDates[numEventDates].day = d;
          numEventDates++;
        }
        numHolidays++;
      }
    }
    if (debug == 1) Serial.println("[APP] Fetched " + String(numHolidays) + " bank holidays");
  } else {
    if (debug == 1) Serial.println("[APP] Bank holidays HTTP request failed: " + String(httpCode));
  }
  http.end();
}
// New function to fetch background filename from server
void fetchBackgroundFilename() {
  if (debug == 1) Serial.println("[APP] Fetching background filename...");
  if (WiFi.status() != WL_CONNECTED) {
    if (debug == 1) Serial.println("[APP] WiFi not connected, skipping background filename fetch");
    return;
  }
  HTTPClient http;
  String url = "https://crontech.uk/background.php?background_img=" + apiCode;
  http.begin(url);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    if (debug == 1) Serial.println("[APP] Background filename response: " + payload);
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (!error) {
      backgroundFilename = doc["filename"].as<String>();
      if (backgroundFilename == "null") backgroundFilename = "";
      if (debug == 1) Serial.println("[APP] Fetched background filename: " + backgroundFilename);
    } else {
      if (debug == 1) Serial.println("[APP] JSON parsing failed: " + String(error.c_str()));
    }
  } else {
    if (debug == 1) Serial.println("[APP] HTTP request failed: " + String(httpCode));
  }
  http.end();
}
// New function to fetch and set background image if filename exists
void fetchAndSetBackgroundImage() {
  if (backgroundFilename.isEmpty()) {
    if (debug == 1) Serial.println("[APP] No background filename, skipping image fetch");
    return;
  }
  if (debug == 1) Serial.println("[APP] Fetching background image: " + backgroundFilename);
  HTTPClient http;
  String url = "https://crontech.uk/uploads/" + backgroundFilename;
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode != HTTP_CODE_OK) {
    if (debug == 1) Serial.println("[APP] Failed to download background image: HTTP " + String(httpCode));
    http.end();
    return;
  }
  int contentLength = http.getSize();
  const int expectedSize = 800 * 480 * 3;  // 1,152,000 bytes for RGB888
  if (contentLength != expectedSize) {
    if (debug == 1) Serial.println("[APP] Invalid background size: " + String(contentLength) + " bytes (expected " + String(expectedSize) + ")");
    http.end();
    return;
  }
  if (debug == 1) Serial.println("[APP] Background size validated: " + String(contentLength) + " bytes");
  uint8_t* imageBuffer = (uint8_t*)ps_malloc(contentLength);
  if (!imageBuffer) {
    if (debug == 1) Serial.println("[APP] Failed to allocate PSRAM for background image (" + String(contentLength) + " bytes)");
    http.end();
    return;
  }
  if (debug == 1) Serial.println("[APP] PSRAM allocated successfully");
  WiFiClient *stream = http.getStreamPtr();
  size_t totalRead = 0;
  while (http.connected() && totalRead < contentLength) {
    size_t available = stream->available();
    if (available) {
      size_t read = stream->readBytes(imageBuffer + totalRead, min(available, (size_t)(contentLength - totalRead)));
      totalRead += read;
    }
    delay(1);
  }
  if (totalRead != contentLength) {
    if (debug == 1) Serial.println("[APP] Incomplete background image download: " + String(totalRead) + "/" + String(contentLength) + " bytes");
    free(imageBuffer);
    http.end();
    return;
  }
  if (debug == 1) Serial.println("[APP] Download complete: " + String(totalRead) + " bytes");
  // Delete previous background if exists
  if (bg_img) {
    lv_obj_del(bg_img);
    bg_img = nullptr;
    if (debug == 1) Serial.println("[APP] Previous background deleted");
  }
  // Setup LVGL image descriptor
  lv_image_dsc_t img_dsc;
  memset(&img_dsc, 0, sizeof(img_dsc));
  img_dsc.header.cf = LV_COLOR_FORMAT_RGB888;
  img_dsc.header.w = 800;
  img_dsc.header.h = 480;
  // Removed: always_zero and reserved (handled by memset in v9 bitfields)
  img_dsc.data_size = contentLength;
  img_dsc.data = imageBuffer;
  // Create and set image
  bg_img = lv_img_create(lv_scr_act());
  lv_img_set_src(bg_img, &img_dsc);
  lv_obj_set_size(bg_img, LV_PCT(100), LV_PCT(100));
  lv_obj_align(bg_img, LV_ALIGN_CENTER, 0, 0);
  lv_obj_move_background(bg_img);
  lv_obj_invalidate(lv_scr_act());  // Force redraw
  if (debug == 1) Serial.println("[APP] Background image set successfully via LVGL");
  http.end();
  // Note: imageBuffer remains allocated as LVGL owns it; free on next update or reboot
}