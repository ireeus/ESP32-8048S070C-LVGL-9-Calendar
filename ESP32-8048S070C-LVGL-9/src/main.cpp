#include "display.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <time.h>
#include <Preferences.h>
#include <Update.h>
#include <WiFiClientSecure.h>
#include <esp_system.h>  // For ESP.restart()
#include <LittleFS.h>     // background-image cache (the unused spiffs partition)
extern const lv_font_t technology_98;
// Build version
const String build_version = "2.1";
int debug =0; // Change to 1 to enable serial prints
// Firmware check interval variable
const unsigned long firmwareCheckInterval = 100000UL; // 5 minutes in milliseconds
// Settings popup geometry (the display is 800x480). The popup uses a
// two-column layout so the whole page is visible at once, and it shrinks to
// the space above the on-screen keyboard while a text field is focused.
// Maximum number of events kept in RAM. The old fetch loop tested for 4000
// while the storage array held only 300, so the effective limit was always the
// array size. 200 is plenty for this calendar and saves ~10KB of static RAM
// versus the old 300 (each Event is ~104 bytes: four Strings + two time_t).
#define MAX_EVENTS 200
#define SETTINGS_POPUP_W 780
#define SETTINGS_POPUP_H 464
// Add-event window geometry. It owns the strip ABOVE the on-screen keyboard, so
// it is 95% of the panel width and its height is derived at run time from the
// keyboard's real measured height (see show_new_event_popup) - that way the two
// can never overlap no matter what font or theme the keyboard ends up using.
#define NEW_EVENT_W 760        // 95% of the 800px panel width
#define NEW_EVENT_TOP 4        // gap between the panel top and the window
#define NEW_EVENT_ROW 34       // height of one single-line field
#define NEW_EVENT_COL2_X 344   // x of the date/time column (left column is 320 wide)
#define NEW_EVENT_FIELD_H 34   // height of a date/time field
// Floating colour-scheme selector (see create_color_changer).
#define SCHEME_BTN_SIZE 44
#define SCHEME_SWATCH_SIZE 26
// Upper bound on event cards built in the side panel. Each card is 3+ LVGL
// objects carrying local styles; building one per event (up to 300) exhausted
// the LVGL heap, so lv_obj_create() started returning NULL and the next call
// dereferenced it - which is what crashed both the boot sequence and the
// settings window. The panel is only 400x175, so a handful is all that is
// readable anyway.
#define MAX_EVENT_CHIPS 10
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
void initTime(); // defined near setup(); declared here for the WiFi wizard
static void wifi_setup_teardown();
static void api_code_teardown();
static void location_teardown();
static void wizard_back_cb(lv_event_t *e);
static void serviceWifiSetup();
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
void update_btn_cb(lv_event_t *e);
void printMemoryUsage();
void updateDateTimeLabel(); // New function to update date-time label
void show_event_details(int index, bool isReminder);
void show_event_details_cb(lv_event_t *e);
void close_event_details_cb(lv_event_t *e);
// Tap-a-day preview: lists the events on that day before offering "add new".
void show_day_events_popup(lv_calendar_date_t *date, const int *indices, int count);
void close_day_events_popup();
void cancel_reminder_cb(lv_event_t *e);
void prev_month_cb(lv_event_t *e);
void next_month_cb(lv_event_t *e);
void updateFirmwareButton();
unsigned long hashString(const String& str);
void update_today_highlight(lv_obj_t *cal);
void darkness_slider_cb(lv_event_t *e); // New callback for darkness slider
// Colour-scheme helpers. Defined next to apply_calendar_theme() further down, but
// declared here because darkness_slider_cb() (which sits above them) calls
// apply_theme_accent() to keep the theme's light/dark flag in step.
static void apply_theme_accent();
static void apply_color_scheme();
static void create_color_changer();
void rearrange_calendar_parts(lv_obj_t *cal);
void fetchNotifications(); // New function for fetching notifications and displaying image
void save_settings_cb(lv_event_t *e); // Renamed and modified from location_submit_cb
void notification_click_cb(lv_event_t *e);
void blink_animation_cb(void * var, int32_t v);
void snooze_reminder_cb(lv_event_t *e);
const lv_image_dsc_t* getWifiImage();
void fetchParcelBoxCredentials(); // New function to fetch parcelbox credentials
void fetchBankHolidays(); // New function to fetch bank holidays
void fetchAfterUiReady(); // Post-UI fetch sequence (bank holidays last)
void updateHolidayLabel(); // New function to update holiday label
void notification_toggle_cb(lv_timer_t *timer);
void notification_hide();
// Parcel-notification dialog. Defined next to show_confirm_popup() further down,
// but declared here because notification_hide() and fetchNotifications() (which
// both sit above it) use them.
static void notification_popup_close();
static bool show_notification_popup(const char *title, const char *message);




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
// WiFi setup screen widgets. Held here instead of dug out with
// lv_obj_get_child(screen, N): the old code assumed the password box was child
// index 1, so any layout change would silently hand it the wrong widget.
static lv_obj_t *api_code_ta = nullptr;      // wizard step 2 field
static lv_obj_t *api_code_status = nullptr;  // wizard step 2 status line
static lv_obj_t *location_ta = nullptr;      // wizard step 3 field
static lv_obj_t *location_status = nullptr;  // wizard step 3 status line
static lv_obj_t *wifi_ssid_dd = nullptr;     // network dropdown
static lv_obj_t *wifi_pass_ta = nullptr;     // password box
static lv_obj_t *wifi_status_lbl = nullptr;  // the one reused status/error line
static bool wifi_scan_pending = false;
static unsigned long wifi_scan_started = 0;
static bool wifi_connect_pending = false;
static unsigned long wifi_connect_started = 0;
static String wifi_pending_ssid = "";
static String wifi_pending_password = "";
static lv_obj_t *api_code_screen = nullptr;
static lv_obj_t *location_screen = nullptr;
static lv_obj_t *settings_popup = nullptr;
static lv_obj_t *new_event_popup = nullptr;
static lv_obj_t *new_event_error_label = nullptr; // single reusable inline error
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
static lv_obj_t *day_events_popup = nullptr; // Day preview (events on a tapped day)
static lv_calendar_date_t day_preview_date = {0, 0, 0}; // Date shown by the day preview
static lv_obj_t *firmware_update_btn = nullptr;
static lv_obj_t *button_bar = nullptr;
static lv_obj_t *prev_btn_obj = nullptr;   // recoloured on scheme change
static lv_obj_t *next_btn_obj = nullptr;   // recoloured on scheme change
static lv_obj_t *color_cont = nullptr;     // floating scheme-swatch strip
static lv_obj_t *color_btn = nullptr;      // floating round palette button
static lv_obj_t *notification_img = nullptr; // New: Object for the notification image
static lv_obj_t *notification_popup = nullptr;        // parcel notification dialog
static lv_obj_t *notification_popup_status = nullptr; // its inline failure line
// The notification a dialog has already been raised for. Kept separate from
// last_ignored_notification, which means "acknowledged, stop showing anything":
// dismissing the dialog must not silence the blinking icon, and it must not make
// the dialog come straight back on the next 10s poll either.
static String notification_popup_shown_for = "";
// Title and body are stored separately because current_notification_text is
// title + "_" + text, and a title may itself contain underscores.
static String current_notification_title = "";
static String current_notification_body = "";
static lv_obj_t *wifi_icon = nullptr; // New: Object for the WiFi signal icon
static bool notification_visible = false;
static lv_timer_t *notification_timer = NULL;
static lv_timer_t *blink_timer = NULL;
static lv_obj_t *bg_img = nullptr;  // Global for background image
static int g_ui_darkness = 0; // Global darkness level (0: light, 100: dark)

// ---- Colour schemes --------------------------------------------------------
// Ported from LVGL's Widgets demo, which swaps between the LV_PALETTE_* accents
// listed below. Two things make this actually do something here:
//   1. LVGL's calendar draws the "today" marker with
//      lv_theme_get_color_primary(), so re-initialising the theme moves it.
//      Widgets that keep their default theme style (keyboard, textareas,
//      dropdown, slider) follow it too.
//   2. Everything this app pins an explicit colour on (129 lv_color_hex() calls,
//      zero lv_theme_* calls) reads the accent from these helpers instead, so a
//      theme swap alone would NOT have been enough.
struct ColorScheme {
  const char *name;
  lv_palette_t palette;
};
static const ColorScheme color_schemes[] = {
  {"Blue",      LV_PALETTE_BLUE},
  {"Green",     LV_PALETTE_GREEN},
  {"Blue Grey", LV_PALETTE_BLUE_GREY},
  {"Orange",    LV_PALETTE_ORANGE},
  {"Red",       LV_PALETTE_RED},
  {"Purple",    LV_PALETTE_PURPLE},
  {"Teal",      LV_PALETTE_TEAL},
  {"Indigo",    LV_PALETTE_INDIGO},
};
#define COLOR_SCHEME_COUNT ((int)(sizeof(color_schemes) / sizeof(color_schemes[0])))
static int g_ui_scheme = 0;

static lv_palette_t scheme_palette()     { return color_schemes[g_ui_scheme].palette; }
static lv_color_t scheme_accent()        { return lv_palette_main(scheme_palette()); }
static lv_color_t scheme_accent_dark()   { return lv_palette_darken(scheme_palette(), 2); }
static lv_color_t scheme_accent_deep()   { return lv_palette_darken(scheme_palette(), 4); }
static lv_color_t scheme_accent_soft()   { return lv_palette_lighten(scheme_palette(), 3); }
static lv_color_t scheme_accent_tint(int lvl) { return lv_palette_lighten(scheme_palette(), (uint8_t)lvl); }
// Event-card colours: light cards get a pale accent wash with deep accent text,
// dark mode gets the dark end of the same palette.
static lv_color_t scheme_card_a()        { return (g_ui_darkness > 50) ? scheme_accent_dark() : scheme_accent_soft(); }
static lv_color_t scheme_card_b()        { return (g_ui_darkness > 50) ? scheme_accent_deep() : scheme_accent(); }
static lv_color_t scheme_card_text()     { return (g_ui_darkness > 50) ? lv_color_hex(0xECEFF1) : scheme_accent_deep(); }
// Text for a card whose background is a FIXED light colour - the "due in more
// than 3 days" cards, which are always the same light blue gradient. Those cards
// do not respond to the brightness slider, so their text must not either:
// scheme_card_text() flipped it to near-white in dark mode and it disappeared
// against the light background. Near-black keeps ~4.6:1 contrast even at the
// darker end of that gradient.
static lv_color_t scheme_text_on_light()  { return lv_color_hex(0x101820); }
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
Event events[MAX_EVENTS];
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
      if (numEvents >= MAX_EVENTS) {
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
// Tear down the notification icon and its blink timer together and reset the
// blink state. The parcel notification can be cleared from the web app at any
// time, so the icon may be removed while its blink timer is still pending; the
// timer must be deleted here as well, otherwise it later fires against a
// NULL/deleted object and crashes in lv_obj_add_flag()/lv_obj_clear_flag().
void notification_hide() {
  if (notification_timer) {
    lv_timer_del(notification_timer);
    notification_timer = NULL;
  }
  if (notification_img) {
    lv_obj_del(notification_img);
    notification_img = nullptr;
  }
  notification_visible = false;
  // The dialog and the blinking icon always go together: both are cleared when
  // the server reports no notification, and after a successful acknowledgement.
  notification_popup_close();
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
      current_notification_title = title;
      current_notification_body = text;
      if (!notification_img) {
        notification_img = lv_img_create(lv_scr_act());
        lv_obj_null_on_delete(&notification_img); // Auto-null if deleted elsewhere
        lv_img_set_src(notification_img, &box);
        lv_img_set_zoom(notification_img, 102);
        lv_obj_align(notification_img, LV_ALIGN_TOP_RIGHT, -110, -13); // Top-right with padding
        lv_obj_add_flag(notification_img, LV_OBJ_FLAG_CLICKABLE); // Make the image clickable
        lv_obj_add_event_cb(notification_img, notification_click_cb, LV_EVENT_CLICKED, NULL);
        lv_obj_clear_flag(notification_img, LV_OBJ_FLAG_HIDDEN);
        notification_visible = true;
        notification_timer = lv_timer_create(notification_toggle_cb, 2000, NULL);
      }
      // Raise the dialog once per notification. show_notification_popup() refuses
      // while another dialog is open; in that case the flag is left unset so the
      // next poll retries and the popup appears as soon as the way is clear.
      if (notification_popup_shown_for != this_notification &&
          show_notification_popup(title.c_str(), text.c_str())) {
        notification_popup_shown_for = this_notification;
      }
    } else {
      if (debug == 1) Serial.println("[APP] No new notification event, hiding image");
      notification_hide(); // this also closes the dialog
      // Cleared so a later notification pops up again - but only when it holds
      // something, because this branch runs on every 10s poll and an Arduino
      // String assignment reallocates even for "".
      if (!notification_popup_shown_for.isEmpty()) notification_popup_shown_for = "";
    }
  } else {
    if (debug == 1) Serial.println("[APP] Notification HTTP request failed: " + String(httpCode));
  }
  http.end();
}
// The blinking icon is a ~20px tap target, so it no longer acknowledges anything
// by itself: it re-opens the dialog, where the button is big enough to hit, and
// the acknowledgement (and its confirmation from the server) happens there.
void notification_click_cb(lv_event_t *e) {
  if (current_notification_title.isEmpty() && current_notification_body.isEmpty()) return;
  if (show_notification_popup(current_notification_title.c_str(),
                              current_notification_body.c_str())) {
    notification_popup_shown_for = current_notification_text;
  }
}
void blink_animation_cb(void * var, int32_t v) {
  lv_obj_set_style_img_opa((lv_obj_t *)var, v, 0);
}
void notification_toggle_cb(lv_timer_t *timer) {
  if (notification_img == nullptr) {
    // The icon was removed (e.g. the notification was cleared from the web app)
    // while this timer was still pending. Stop the timer instead of touching a
    // dead object. Deleting a timer from inside its own callback is supported
    // by LVGL v9 (the handler restarts its iteration).
    notification_hide();
    return;
  }
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
  // The three chips were a teal / pink / blue jumble. They are now three tints
  // of the active scheme's accent - still distinguishable, but they read as one
  // set instead of three unrelated hues.
  lv_obj_set_style_bg_color(hum_cont, scheme_accent_tint(4), 0);
  lv_obj_set_style_bg_grad_color(hum_cont, scheme_accent_tint(3), 0);
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
  lv_obj_set_style_bg_color(wind_cont, scheme_accent_tint(3), 0);
  lv_obj_set_style_bg_grad_color(wind_cont, scheme_accent_tint(2), 0);
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
lv_obj_set_style_bg_color(pressure_cont, scheme_accent_tint(2), 0);
lv_obj_set_style_bg_grad_color(pressure_cont, scheme_accent_tint(1), 0);
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
  // IMPORTANT: lv_calendar_set_highlighted_dates() stores this pointer, it does
  // NOT copy the array. A stack array here dangles as soon as we return, and
  // LVGL later walks it (on month change / today update) and writes button
  // states from whatever garbage now occupies that stack. static keeps it valid
  // for the lifetime of the program; it also moves ~12KB off the loop stack.
  static lv_calendar_date_t highlighted_dates[1000];
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
  for (int i = 0; i < numEvents && total_displayed < MAX_EVENT_CHIPS; i++) {
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
  for (int i = 0; i < numEvents && total_displayed < MAX_EVENT_CHIPS; i++) {
    if (events[i].isToday) {
      lv_obj_t *event_cont = lv_obj_create(eventContainer);
      lv_obj_set_size(event_cont, 361, 65);
      lv_obj_align(event_cont, LV_ALIGN_TOP_MID, 0, y_offset);
      // Card tint now follows the active scheme. The fixed pink gradient with
      // pure-blue and maroon text on top of it was the worst offender for
      // contrast, and it is where most of the "poor colours" impression came from.
      lv_obj_set_style_bg_color(event_cont, scheme_card_a(), 0);
      lv_obj_set_style_bg_grad_color(event_cont, scheme_card_b(), 0);
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
      lv_obj_set_style_text_color(title_label, scheme_card_text(), 0);
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
        lv_obj_set_style_text_color(all_day_label, scheme_card_text(), 0);
        lv_obj_set_style_text_font(all_day_label, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_align(all_day_label, LV_TEXT_ALIGN_LEFT, 0);
        lv_obj_align_to(all_day_label, title_label, LV_ALIGN_OUT_BOTTOM_LEFT, 0, 0);
        time_base = all_day_label;
      } else {
        lv_obj_t *from_label = lv_label_create(event_cont);
        lv_label_set_text(from_label, "from ");
        lv_obj_set_style_text_color(from_label, scheme_card_text(), 0);
        lv_obj_set_style_text_font(from_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(from_label, title_label, LV_ALIGN_OUT_BOTTOM_LEFT, 0, 0);
        lv_obj_t *start_time_label = lv_label_create(event_cont);
        lv_label_set_text(start_time_label, start_time_str.c_str());
        lv_obj_set_style_text_color(start_time_label, scheme_card_text(), 0);
        lv_obj_set_style_text_font(start_time_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(start_time_label, from_label, LV_ALIGN_OUT_RIGHT_MID, 0, 0);
        lv_obj_t *to_label = lv_label_create(event_cont);
        lv_label_set_text(to_label, " to ");
        lv_obj_set_style_text_color(to_label, scheme_card_text(), 0);
        lv_obj_set_style_text_font(to_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(to_label, start_time_label, LV_ALIGN_OUT_RIGHT_MID, 0, 0);
        lv_obj_t *end_time_label = lv_label_create(event_cont);
        lv_label_set_text(end_time_label, end_time_str.c_str());
        lv_obj_set_style_text_color(end_time_label, scheme_card_text(), 0);
        lv_obj_set_style_text_font(end_time_label, &lv_font_montserrat_14, 0);
        lv_obj_align_to(end_time_label, to_label, LV_ALIGN_OUT_RIGHT_MID, 0, 0);
        time_base = from_label;
      }

      // Description (if present)
      if (description.length() > 0) {
        lv_obj_t *desc_label = lv_label_create(event_cont);
        lv_label_set_text(desc_label, description.c_str());
        // This card's background DOES follow the brightness slider, so this label
        // has to match the rest of the card - a fixed dark grey went unreadable
        // against the dark background in dark mode.
        lv_obj_set_style_text_color(desc_label, scheme_card_text(), 0);
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

  if (upcoming_count > 0 && total_displayed < MAX_EVENT_CHIPS) {
    // Upcoming label
    lv_obj_t *upcoming_label = lv_label_create(eventContainer);
    lv_label_set_text(upcoming_label, "Due in more than 3 days");
    lv_obj_set_style_text_font(upcoming_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(upcoming_label, lv_color_hex(0xCAE4CA), 0);
    lv_obj_align(upcoming_label, LV_ALIGN_TOP_LEFT, 10, y_offset);
    y_offset += 25;

    // Display upcoming events (similar to today's, but with date)
    for (int i = 0; i < numEvents && total_displayed < MAX_EVENT_CHIPS; i++) {
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
        lv_obj_set_style_text_color(title_label, scheme_text_on_light(), 0);
        lv_obj_set_style_text_font(title_label, &lv_font_montserrat_14, 0);
        lv_obj_set_style_text_align(title_label, LV_TEXT_ALIGN_LEFT, 0);
        lv_label_set_long_mode(title_label, LV_LABEL_LONG_WRAP);
        lv_obj_set_width(title_label, lv_pct(100));
        lv_obj_align(title_label, LV_ALIGN_TOP_LEFT, 0, 0);

        // Date row
        lv_obj_t *date_label = lv_label_create(event_cont);
        lv_label_set_text(date_label, ("On " + start_date_str).c_str());
        lv_obj_set_style_text_color(date_label, scheme_text_on_light(), 0);
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

// ---------------------------------------------------------------------------
// First-run wizard chrome.
// All three steps (1 WiFi -> 2 API code -> 3 Location) are built from these, so
// the flow looks like one wizard instead of three unrelated screens. The geometry
// keeps every control above y=290, which is where the on-screen keyboard starts,
// so a field or button is never trapped underneath it.
// ---------------------------------------------------------------------------
#define WIZARD_STEP_COUNT 3
#define WIZARD_FIELD_H 40
#define WIZARD_ROW_Y 92    // first caption
#define WIZARD_ROW_GAP 56  // caption-to-caption
#define WIZARD_BTN_Y 216

static void wizard_set_status(lv_obj_t *lbl, const char *msg, bool is_error) {
  if (!lbl) return;
  lv_label_set_text(lbl, msg);
  lv_obj_set_style_text_color(lbl, lv_color_hex(is_error ? 0xFF5252 : 0x8BC34A), 0);
}
// Shared shell: title, "Step N of 3", subtitle, and the shared keyboard.
static lv_obj_t *wizard_screen(const char *title, int step, const char *subtitle) {
  lv_obj_t *scr = lv_obj_create(lv_scr_act());
  lv_obj_set_size(scr, 800, 480);
  lv_obj_set_style_bg_color(scr, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_width(scr, 0, 0);
  lv_obj_set_style_radius(scr, 0, 0);
  lv_obj_set_style_pad_all(scr, 0, 0);
  lv_obj_clear_flag(scr, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(scr, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *t = lv_label_create(scr);
  lv_label_set_text(t, title);
  lv_obj_set_style_text_font(t, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(t, lv_color_hex(0xFFFFFF), 0);
  lv_obj_align(t, LV_ALIGN_TOP_LEFT, 20, 10);

  lv_obj_t *step_lbl = lv_label_create(scr);
  lv_label_set_text_fmt(step_lbl, "Step %d of %d", step, WIZARD_STEP_COUNT);
  lv_obj_set_style_text_font(step_lbl, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(step_lbl, lv_color_hex(0x9FB3C8), 0);
  lv_obj_align(step_lbl, LV_ALIGN_TOP_RIGHT, -20, 16);

  lv_obj_t *sub = lv_label_create(scr);
  lv_label_set_text(sub, subtitle);
  lv_obj_set_style_text_font(sub, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(sub, lv_color_hex(0xAAAAAA), 0);
  lv_label_set_long_mode(sub, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(sub, 420);
  lv_obj_align(sub, LV_ALIGN_TOP_LEFT, 20, 48);

  if (!keyboard) {
    keyboard = lv_keyboard_create(lv_scr_act());
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
  }
  return scr;
}
static void wizard_caption(lv_obj_t *scr, const char *text, int slot) {
  lv_obj_t *c = lv_label_create(scr);
  lv_label_set_text(c, text);
  lv_obj_set_style_text_font(c, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(c, lv_color_hex(0xAAAAAA), 0);
  lv_obj_align(c, LV_ALIGN_TOP_LEFT, 20, WIZARD_ROW_Y + slot * WIZARD_ROW_GAP);
}
static lv_obj_t *wizard_field(lv_obj_t *scr, int slot, lv_coord_t w, const char *placeholder,
                              bool password) {
  lv_obj_t *ta = lv_textarea_create(scr);
  lv_textarea_set_one_line(ta, true);
  lv_textarea_set_placeholder_text(ta, placeholder);
  if (password) lv_textarea_set_password_mode(ta, true);
  lv_obj_set_size(ta, w, WIZARD_FIELD_H);
  lv_obj_align(ta, LV_ALIGN_TOP_LEFT, 20, WIZARD_ROW_Y + 18 + slot * WIZARD_ROW_GAP);
  lv_obj_set_style_text_font(ta, &lv_font_montserrat_14, 0);
  lv_obj_set_style_pad_all(ta, 4, 0);
  lv_obj_add_event_cb(ta, keyboard_event_cb, LV_EVENT_FOCUSED, ta);
  lv_obj_add_event_cb(ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ta);
  return ta;
}
static void wizard_button(lv_obj_t *scr, const char *text, lv_coord_t x, lv_coord_t y,
                          lv_coord_t w, lv_color_t bg, lv_event_cb_t cb, void *ud) {
  lv_obj_t *b = lv_button_create(scr);
  lv_obj_set_size(b, w, 44);
  lv_obj_align(b, LV_ALIGN_TOP_LEFT, x, y);
  lv_obj_set_style_bg_color(b, bg, 0);
  lv_obj_set_style_radius(b, 10, 0);
  lv_obj_add_event_cb(b, cb, LV_EVENT_PRESSED, ud);
  lv_obj_t *l = lv_label_create(b);
  lv_label_set_text(l, text);
  lv_obj_center(l);
  lv_obj_set_style_text_font(l, &lv_font_montserrat_14, 0);
}
static lv_obj_t *wizard_dropdown(lv_obj_t *scr, int slot, lv_coord_t w) {
  lv_obj_t *dd = lv_dropdown_create(scr);
  lv_obj_set_size(dd, w, WIZARD_FIELD_H);
  lv_obj_align(dd, LV_ALIGN_TOP_LEFT, 20, WIZARD_ROW_Y + 18 + slot * WIZARD_ROW_GAP);
  lv_obj_set_style_text_font(dd, &lv_font_montserrat_14, 0);
  return dd;
}
static lv_obj_t *wizard_status_label(lv_obj_t *scr) {
  lv_obj_t *sl = lv_label_create(scr);
  lv_label_set_text(sl, "");
  lv_obj_set_style_text_font(sl, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(sl, lv_color_hex(0x8BC34A), 0);
  lv_obj_set_width(sl, 420);
  lv_label_set_long_mode(sl, LV_LABEL_LONG_WRAP);
  lv_obj_align(sl, LV_ALIGN_TOP_LEFT, 20, 268);
  return sl;
}
// Right-hand QR panel. Aligned from the TOP; the old screens used
// LV_ALIGN_BOTTOM_MID with a POSITIVE y offset, which pushed the code below the
// bottom edge of the screen.
static void wizard_qr(lv_obj_t *scr, const char *caption) {
  lv_obj_t *img = lv_img_create(scr);
  lv_img_set_src(img, &qr);
  lv_img_set_zoom(img, 120); // 298px source -> ~140px
  lv_obj_align(img, LV_ALIGN_TOP_RIGHT, -115, 60);
  // No img_recolor: the style needs img_recolor_opa to apply, and COVER would
  // flatten the code to one colour and make it unscannable.
  lv_obj_t *lbl = lv_label_create(scr);
  lv_label_set_text(lbl, caption);
  lv_obj_set_style_text_font(lbl, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(lbl, lv_color_hex(0xFFFFFF), 0);
  lv_label_set_long_mode(lbl, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(lbl, 300);
  lv_obj_set_style_text_align(lbl, LV_TEXT_ALIGN_CENTER, 0);
  lv_obj_align_to(lbl, img, LV_ALIGN_OUT_BOTTOM_MID, 0, 14);
}
// Step 1 <-> 2 <-> 3 navigation.
static void wizard_back_cb(lv_event_t *e) {
  intptr_t from = (intptr_t)lv_event_get_user_data(e);
  if (from == 2) {
    api_code_teardown();
    show_wifi_setup_screen();
  } else if (from == 3) {
    location_teardown();
    show_api_code_screen();
  }
}
// Drop a step's screen outright. The old code only hid the API code screen, so it
// and its QR image stayed on lv_scr_act() for the rest of the session.
static void api_code_teardown() {
  api_code_ta = nullptr;
  api_code_status = nullptr;
  if (api_code_screen) {
    lv_obj_del(api_code_screen);
    api_code_screen = nullptr;
  }
}
static void location_teardown() {
  location_ta = nullptr;
  location_status = nullptr;
  if (location_screen) {
    lv_obj_del(location_screen);
    location_screen = nullptr;
  }
}
// ---- WiFi setup: network list + connection state machine -------------------
// Nothing here blocks any more. The old code ran a blocking WiFi.scanNetworks()
// before it created a single widget (so the screen stayed black for 1-3s) and
// then blocked for up to 10s inside the Connect handler with no feedback at all.
#define WIFI_MAX_SSID_SHOWN 10
#define WIFI_SCAN_TIMEOUT_MS 20000UL
#define WIFI_CONNECT_TIMEOUT_MS 20000UL

static void wifi_fill_network_list() {
  if (!wifi_ssid_dd) return;
  int n = WiFi.scanComplete();
  if (n < 0) {
    // -1 while still running, -2 if it never started. Bound it with a timeout
    // rather than depending on those two constants.
    if (millis() - wifi_scan_started < WIFI_SCAN_TIMEOUT_MS) return;
    n = 0;
  }
  wifi_scan_pending = false;
  if (n == 0) {
    lv_dropdown_set_options(wifi_ssid_dd, "No networks found");
    wizard_set_status(wifi_status_lbl, "No networks found - check the router is in range", true);
    return;
  }
  String list;
  int shown = (n < WIFI_MAX_SSID_SHOWN) ? n : WIFI_MAX_SSID_SHOWN;
  for (int i = 0; i < shown; i++) {
    if (i) list += "\n"; // separator BETWEEN entries, so no trailing blank option
    list += WiFi.SSID(i);
  }
  lv_dropdown_set_options(wifi_ssid_dd, list.c_str());
  lv_dropdown_set_selected(wifi_ssid_dd, 0);
  wizard_set_status(wifi_status_lbl, "", false);
}
static void wifi_scan_start() {
  if (wifi_scan_pending) return;
  if (wifi_ssid_dd) lv_dropdown_set_options(wifi_ssid_dd, "Scanning...");
  wizard_set_status(wifi_status_lbl, "Scanning for networks...", false);
  WiFi.scanDelete();      // release the previous result first
  wifi_scan_started = millis();
  wifi_scan_pending = true;
  WiFi.scanNetworks(true); // asynchronous: returns immediately
}
// Single teardown for the screen, so the widget pointers can never dangle.
static void wifi_setup_teardown() {
  wifi_scan_pending = false;
  wifi_connect_pending = false;
  wifi_ssid_dd = nullptr;
  wifi_pass_ta = nullptr;
  wifi_status_lbl = nullptr;
  if (wifi_setup_screen) {
    lv_obj_del(wifi_setup_screen);
    wifi_setup_screen = nullptr;
  }
}
// Carry on with the first-run wizard now that we are online.
static void wifi_after_connected() {
  initTime(); // everything downstream needs a correct clock
  preferences.begin("api", false);
  apiCode = preferences.getString("apiCode", "");
  preferences.end();
  if (apiCode == "") {
    show_api_code_screen();
    return;
  }
  preferences.begin("location", false);
  location = preferences.getString("location", "");
  preferences.end();
  if (location == "") {
    show_location_screen();
    return;
  }
  setup_calendar();
  fetchAfterUiReady();
}
static void wifi_connect_start() {
  if (wifi_connect_pending) return;
  if (!wifi_ssid_dd) return;
  char selected[33] = {0};
  lv_dropdown_get_selected_str(wifi_ssid_dd, selected, sizeof(selected));
  String want = String(selected);
  // With an empty list the dropdown is showing one of these placeholders.
  if (want.isEmpty() || want == "Scanning..." || want == "No networks found") {
    wizard_set_status(wifi_status_lbl, "Pick a network from the list first", true);
    return;
  }
  wifi_pending_ssid = want;
  wifi_pending_password = String(wifi_pass_ta ? lv_textarea_get_text(wifi_pass_ta) : "");
  wifi_connect_started = millis();
  wifi_connect_pending = true;
  String msg = "Connecting to " + want + "...";
  wizard_set_status(wifi_status_lbl, msg.c_str(), false);
  WiFi.disconnect(); // clear any half-open attempt so begin() starts clean
  WiFi.begin(wifi_pending_ssid.c_str(), wifi_pending_password.c_str());
}
// Driven from loop(): finishes the scan and completes or aborts a connection.
static void serviceWifiSetup() {
  if (wifi_scan_pending) wifi_fill_network_list();
  if (!wifi_connect_pending) return;
  if (WiFi.status() == WL_CONNECTED) {
    wifi_connect_pending = false;
    preferences.begin("wifi", false);
    preferences.putString("ssid", wifi_pending_ssid);
    preferences.putString("password", wifi_pending_password);
    preferences.end();
    ssid = wifi_pending_ssid;
    password = wifi_pending_password;
    wifi_setup_teardown();
    wifi_after_connected();
    return;
  }
  if (millis() - wifi_connect_started >= WIFI_CONNECT_TIMEOUT_MS) {
    wifi_connect_pending = false;
    WiFi.disconnect();
    wizard_set_status(wifi_status_lbl, "Connection failed - check the password and try again", true);
  }
}
void wifi_connect_cb(lv_event_t * e) {
  (void)e;
  wifi_connect_start();
}
static void wifi_rescan_cb(lv_event_t * e) {
  (void)e;
  wifi_scan_start();
}
void api_code_submit_cb(lv_event_t * e) {
  (void)e;
  if (debug == 1) Serial.println("[APP] API code submit button clicked");
  if (!api_code_ta) return;
  String code = String(lv_textarea_get_text(api_code_ta));
  code.trim();
  if (code.isEmpty()) {
    // The old handler saved whatever was in the box, including nothing, and moved
    // on - leaving every API call to fail later with no explanation.
    wizard_set_status(api_code_status, "Enter the API code before continuing", true);
    return;
  }
  apiCode = code;
  preferences.begin("api", false);
  preferences.putString("apiCode", apiCode);
  preferences.end();
  if (debug == 1) Serial.println("[APP] API code saved");
  api_code_teardown(); // delete it, do not just hide it
  preferences.begin("location", false);
  location = preferences.getString("location", "");
  preferences.end();
  if (location == "") {
    show_location_screen();
  } else {
    setup_calendar();
    fetchAfterUiReady();
  }
}
void location_submit_cb(lv_event_t * e) {
  (void)e;
  if (debug == 1) Serial.println("[APP] Location submit button clicked");
  if (!location_ta) return;
  String want = String(lv_textarea_get_text(location_ta));
  want.trim();
  if (want.isEmpty()) {
    wizard_set_status(location_status, "Enter a city before continuing", true);
    return;
  }
  wizard_set_status(location_status, "Looking that place up...", false);
  // Paint the status before the blocking request, otherwise it is set and never
  // seen.
  lv_refr_now(NULL);

  HTTPClient http;
  String url = "https://crontech.uk/api.php?weatherLocation=" + URLEncode(apiCode) + "&city_name=" + URLEncode(want);
  http.begin(url);
  int httpCode = http.GET();
  bool ok = false;
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, payload);
    if (!error) {
      String got = doc["city_name"].as<String>();
      if (!got.isEmpty()) {
        location = got;
        lat = String(doc["latitude"].as<float>(), 6);
        lon = String(doc["longitude"].as<float>(), 6);
        preferences.begin("location", false);
        preferences.putString("location", location);
        preferences.putString("lat", lat);
        preferences.putString("lon", lon);
        preferences.end();
        if (debug == 1) Serial.println("[APP] Weather location saved: " + location + ", lat: " + lat + ", lon: " + lon);
        ok = true;
      }
    } else if (debug == 1) {
      Serial.println("[APP] JSON parsing failed: " + String(error.c_str()));
    }
  } else if (debug == 1) {
    Serial.println("[APP] Weather location update failed: " + String(httpCode));
  }
  http.end();

  if (!ok) {
    // Stay on this step so it can be retried. The old code hid and deleted the
    // screen and carried on regardless, so a failed lookup left the device with
    // no location and the user with no idea why.
    wizard_set_status(location_status,
                      "Could not find that place. Check the spelling, and that the API code is right.",
                      true);
    return;
  }
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  location_teardown();
  setup_calendar();
  fetchAfterUiReady();
}
// ---------------------------------------------------------------------------
// Confirmation dialog for destructive actions (log out / factory reset).
// ---------------------------------------------------------------------------
static lv_obj_t *confirm_popup = nullptr;
static void (*confirm_action)() = nullptr;

void close_confirm_popup() {
  if (confirm_popup) {
    lv_obj_del(confirm_popup);
    confirm_popup = nullptr;
  }
  confirm_action = nullptr;
}
static void confirm_cancel_cb(lv_event_t *e) {
  close_confirm_popup();
}
static void confirm_ok_cb(lv_event_t *e) {
  void (*action)() = confirm_action;
  close_confirm_popup(); // also clears confirm_action
  if (action) action();
}
// Warning dialog. `action` runs ONLY after the user presses the confirm button,
// so nothing destructive happens on a stray tap.
void show_confirm_popup(const char *title, const char *message, const char *confirm_label,
                        void (*action)()) {
  if (confirm_popup) return; // one at a time
  confirm_action = action;

  confirm_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(confirm_popup, 560, 250);
  lv_obj_align(confirm_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(confirm_popup, lv_color_hex(0x1A1A1A), 0);
  lv_obj_set_style_border_color(confirm_popup, lv_color_hex(0xFFB300), 0); // amber warning
  lv_obj_set_style_border_width(confirm_popup, 3, 0);
  lv_obj_set_style_radius(confirm_popup, 10, 0);
  lv_obj_set_style_pad_all(confirm_popup, 14, 0);
  lv_obj_set_style_pad_row(confirm_popup, 10, 0);
  lv_obj_set_flex_flow(confirm_popup, LV_FLEX_FLOW_COLUMN);
  lv_obj_set_flex_align(confirm_popup, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(confirm_popup, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(confirm_popup, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *title_lbl = lv_label_create(confirm_popup);
  lv_label_set_text(title_lbl, (String(LV_SYMBOL_WARNING "  ") + title).c_str());
  lv_obj_set_style_text_font(title_lbl, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(title_lbl, lv_color_hex(0xFFB300), 0);

  lv_obj_t *msg_lbl = lv_label_create(confirm_popup);
  lv_label_set_text(msg_lbl, message);
  lv_obj_set_style_text_font(msg_lbl, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(msg_lbl, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_width(msg_lbl, LV_PCT(100));
  lv_obj_set_style_text_align(msg_lbl, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(msg_lbl, LV_LABEL_LONG_WRAP);

  lv_obj_t *btn_row = lv_obj_create(confirm_popup);
  lv_obj_set_width(btn_row, LV_PCT(100));
  lv_obj_set_height(btn_row, LV_SIZE_CONTENT);
  lv_obj_set_style_bg_opa(btn_row, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(btn_row, 0, 0);
  lv_obj_set_style_pad_all(btn_row, 0, 0);
  lv_obj_set_style_pad_column(btn_row, 16, 0);
  lv_obj_set_flex_flow(btn_row, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(btn_row, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(btn_row, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(btn_row, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *cancel_btn = lv_button_create(btn_row);
  lv_obj_set_size(cancel_btn, 150, 46);
  lv_obj_set_style_bg_color(cancel_btn, lv_color_hex(0x555555), 0);
  lv_obj_set_style_radius(cancel_btn, 10, 0);
  lv_obj_add_event_cb(cancel_btn, confirm_cancel_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *cancel_lbl = lv_label_create(cancel_btn);
  lv_label_set_text(cancel_lbl, "Cancel");
  lv_obj_center(cancel_lbl);
  lv_obj_set_style_text_font(cancel_lbl, &lv_font_montserrat_14, 0);

  lv_obj_t *ok_btn = lv_button_create(btn_row);
  lv_obj_set_size(ok_btn, 180, 46);
  lv_obj_set_style_bg_color(ok_btn, lv_color_hex(0xD32F2F), 0);
  lv_obj_set_style_radius(ok_btn, 10, 0);
  lv_obj_add_event_cb(ok_btn, confirm_ok_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *ok_lbl = lv_label_create(ok_btn);
  lv_label_set_text(ok_lbl, confirm_label);
  lv_obj_center(ok_lbl);
  lv_obj_set_style_text_font(ok_lbl, &lv_font_montserrat_14, 0);
}

// ---------------------------------------------------------------------------
// Parcel-box notification dialog.
// A new parcel notification raises this instead of relying on the small blinking
// icon next to the clock. "Acknowledge" sends the same mark-as-read request the
// icon used to send, and the dialog closes ONLY when the server accepted it -
// otherwise it stays put with an error so the request can be retried.
// ---------------------------------------------------------------------------
static bool notification_mark_read() {
  HTTPClient http;
  String url = "https://cloudapps.zapto.org/spb/api.php?device_id=" + device_id +
               "&username=" + username + "&id_read_status=1";
  if (debug == 1) Serial.println("[APP] Marking notification as read: " + url);
  http.begin(url);
  int httpCode = http.GET();
  bool accepted = (httpCode == HTTP_CODE_OK);
  if (debug == 1) {
    Serial.println(accepted ? String("[APP] Marked as read successfully")
                            : String("[APP] Failed to mark as read: ") + String(httpCode));
  }
  http.end();
  // Stops the poll raising this same notification again.
  if (accepted) last_ignored_notification = current_notification_text;
  return accepted;
}
static void notification_popup_close() {
  if (notification_popup) {
    lv_obj_del(notification_popup);
    notification_popup = nullptr;
  }
  notification_popup_status = nullptr; // it was a child, so it died with the dialog
}
static void notification_dismiss_cb(lv_event_t *e) {
  // Closed without acknowledging: the icon keeps blinking and this notification
  // will not pop up again (notification_popup_shown_for still holds it). Tapping
  // the icon brings the dialog back.
  notification_popup_close();
}
static void notification_ack_cb(lv_event_t *e) {
  if (notification_mark_read()) {
    // Accepted by the server, so the dialog goes away - and so does the icon.
    notification_hide();
  } else if (notification_popup_status) {
    // Keep the dialog up so the request can be retried.
    lv_label_set_text(notification_popup_status,
                      "Server did not accept it. Tap Acknowledge to try again.");
  }
}
static bool show_notification_popup(const char *title, const char *message) {
  if (notification_popup) return false; // one at a time
  // Never stack on top of a dialog the user already has open. The caller leaves
  // its "already shown" flag unset, so it retries on the next poll.
  if (new_event_popup || event_details_popup || day_events_popup || settings_popup ||
      update_popup || confirm_popup) return false;

  // Bound the height: the popup is content-sized, and a very long parcel message
  // would otherwise grow it past the bottom of the screen. lv_label_set_text()
  // copies, so these locals can go out of scope safely.
  String head = title ? String(title) : String("");
  String body = message ? String(message) : String("");
  if (head.length() > 60) head = head.substring(0, 60) + "...";
  if (body.length() > 180) body = body.substring(0, 180) + "...";

  notification_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_width(notification_popup, 600);
  lv_obj_set_height(notification_popup, LV_SIZE_CONTENT);
  lv_obj_align(notification_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(notification_popup, lv_color_hex(0x1A1A1A), 0);
  lv_obj_set_style_border_color(notification_popup, scheme_accent(), 0);
  lv_obj_set_style_border_width(notification_popup, 3, 0);
  lv_obj_set_style_radius(notification_popup, 10, 0);
  lv_obj_set_style_pad_all(notification_popup, 14, 0);
  lv_obj_set_style_pad_row(notification_popup, 8, 0);
  lv_obj_set_flex_flow(notification_popup, LV_FLEX_FLOW_COLUMN);
  lv_obj_set_flex_align(notification_popup, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER,
                        LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(notification_popup, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(notification_popup, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *title_lbl = lv_label_create(notification_popup);
  lv_label_set_text(title_lbl, (String(LV_SYMBOL_BELL "  ") + head).c_str());
  lv_obj_set_style_text_font(title_lbl, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(title_lbl, scheme_accent(), 0);
  lv_obj_set_width(title_lbl, LV_PCT(100));
  lv_obj_set_style_text_align(title_lbl, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(title_lbl, LV_LABEL_LONG_WRAP);

  lv_obj_t *msg_lbl = lv_label_create(notification_popup);
  lv_label_set_text(msg_lbl, body.c_str());
  lv_obj_set_style_text_font(msg_lbl, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(msg_lbl, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_width(msg_lbl, LV_PCT(100));
  lv_obj_set_style_text_align(msg_lbl, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(msg_lbl, LV_LABEL_LONG_WRAP);

  // Empty until the server rejects an acknowledgement, so it costs no height.
  notification_popup_status = lv_label_create(notification_popup);
  lv_label_set_text(notification_popup_status, "");
  lv_obj_set_style_text_font(notification_popup_status, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(notification_popup_status, lv_color_hex(0xFF5252), 0);
  lv_obj_set_width(notification_popup_status, LV_PCT(100));
  lv_obj_set_style_text_align(notification_popup_status, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(notification_popup_status, LV_LABEL_LONG_WRAP);

  lv_obj_t *btn_row = lv_obj_create(notification_popup);
  lv_obj_set_width(btn_row, LV_PCT(100));
  lv_obj_set_height(btn_row, LV_SIZE_CONTENT);
  lv_obj_set_style_bg_opa(btn_row, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(btn_row, 0, 0);
  lv_obj_set_style_pad_all(btn_row, 0, 0);
  lv_obj_set_style_pad_column(btn_row, 16, 0);
  lv_obj_set_flex_flow(btn_row, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(btn_row, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER,
                        LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(btn_row, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(btn_row, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *dismiss_btn = lv_button_create(btn_row);
  lv_obj_set_size(dismiss_btn, 150, 46);
  lv_obj_set_style_bg_color(dismiss_btn, lv_color_hex(0x555555), 0);
  lv_obj_set_style_radius(dismiss_btn, 10, 0);
  lv_obj_add_event_cb(dismiss_btn, notification_dismiss_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *dismiss_lbl = lv_label_create(dismiss_btn);
  lv_label_set_text(dismiss_lbl, "Dismiss");
  lv_obj_center(dismiss_lbl);
  lv_obj_set_style_text_font(dismiss_lbl, &lv_font_montserrat_14, 0);

  lv_obj_t *ack_btn = lv_button_create(btn_row);
  lv_obj_set_size(ack_btn, 220, 46);
  lv_obj_set_style_bg_color(ack_btn, scheme_accent(), 0);
  lv_obj_set_style_bg_color(ack_btn, scheme_accent_dark(), LV_STATE_PRESSED);
  lv_obj_set_style_radius(ack_btn, 10, 0);
  lv_obj_add_event_cb(ack_btn, notification_ack_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *ack_lbl = lv_label_create(ack_btn);
  lv_label_set_text(ack_lbl, "Acknowledge");
  lv_obj_center(ack_lbl);
  lv_obj_set_style_text_font(ack_lbl, &lv_font_montserrat_14, 0);
  return true;
}

static void do_wifi_logout() {
  preferences.begin("wifi", false);
  preferences.clear();
  preferences.end();
  WiFi.disconnect();
  wifi_setup_teardown(); // also clears the widget pointers
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  show_wifi_setup_screen();
}
void wifi_logout_cb(lv_event_t * e) {
  show_confirm_popup("WiFi Logout",
                     "This erases the saved WiFi network and reopens the WiFi setup screen. Continue?",
                     "Log Out", do_wifi_logout);
}

static void do_api_logout() {
  preferences.begin("api", false);
  preferences.clear();
  preferences.end();
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
  show_api_code_screen();
}
void api_logout_cb(lv_event_t * e) {
  show_confirm_popup("API Logout",
                     "This erases the saved API code and reopens the API setup screen. Continue?",
                     "Log Out", do_api_logout);
}
// True when 'obj' is 'ancestor' itself or one of its descendants.
static bool is_descendant_of(lv_obj_t *obj, lv_obj_t *ancestor) {
    if (!obj || !ancestor) return false;
    while (obj) {
        if (obj == ancestor) return true;
        obj = lv_obj_get_parent(obj);
    }
    return false;
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
            // v9 equivalent of the v8 lv_obj_move_foreground() helper. That
            // helper is a static inline in lv_api_map_v8.h which calls
            // LV_LOG_WARN -> lv_log_add, and lv_log_add does not exist when
            // LV_USE_LOG is 0, so calling it fails to link.
            lv_obj_t *kb_parent = lv_obj_get_parent(keyboard);
            if (kb_parent) lv_obj_move_to_index(keyboard, lv_obj_get_child_count(kb_parent) - 1);
            if (settings_popup && is_descendant_of(ta, settings_popup)) {
                // Settings window: shrink it to the area above the keyboard and
                // scroll the focused field into view, so it is never hidden
                // behind the keyboard.
                lv_coord_t kb_h = lv_obj_get_height(keyboard);
                lv_coord_t scr_h = lv_disp_get_ver_res(lv_disp_get_default());
                lv_coord_t h = scr_h - kb_h - 24;
                if (h < 140) h = 140; // keep a usable window even with a tall keyboard
                lv_obj_set_height(settings_popup, h);
                lv_obj_align(settings_popup, LV_ALIGN_TOP_MID, 0, 8);
                lv_obj_scroll_to_view_recursive(ta, LV_ANIM_ON);
            } else if (new_event_popup && is_descendant_of(ta, new_event_popup)) {
                // Add-event window: show_new_event_popup already sizes it from
                // the keyboard's measured height, so it sits entirely in the
                // strip above the keyboard and must NOT be nudged. The generic
                // "shift the parent up by the overlap" path below used to walk
                // the window off the top of the screen while leaving the
                // focused field behind the keyboard.
            } else {
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
            }
        } else {
            if (debug == 1) Serial.println("[APP] Error: Invalid textarea or keyboard in keyboard_event_cb");
        }
    } else if (code == LV_EVENT_DEFOCUSED) {
        if (keyboard) {
            lv_keyboard_set_textarea(keyboard, nullptr);
            lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
            if (settings_popup && is_descendant_of(ta, settings_popup)) {
                // Restore the full-size, centred settings window.
                lv_obj_set_height(settings_popup, SETTINGS_POPUP_H);
                lv_obj_align(settings_popup, LV_ALIGN_CENTER, 0, 0);
                lv_obj_scroll_to_y(settings_popup, 0, LV_ANIM_OFF);
            } else {
                lv_obj_t *par = lv_obj_get_parent(ta);
                if (par == new_event_popup) {
                    // Keep the add-event window pinned to the top strip when the
                    // keyboard hides; re-centring it here is what made the form
                    // jump on every focus change.
                    lv_obj_align(par, LV_ALIGN_TOP_MID, 0, NEW_EVENT_TOP);
                } else if (par && (par == location_screen || par == api_code_screen || par == wifi_setup_screen)) {
                    // These are full-screen forms, so re-centring is a no-op that
                    // undoes the generic overlap shift above.
                    lv_obj_align(par, LV_ALIGN_CENTER, 0, 0);
                }
            }
        }
    }
}
void show_wifi_setup_screen() {
  if (wifi_setup_screen) return; // already up; never build a second one
  if (debug == 1) Serial.println("[APP] Showing WiFi setup screen...");
  wifi_setup_screen = wizard_screen("WiFi Setup", 1,
      "Pick your network and enter its password. Tap Rescan if yours is not listed.");

  wizard_caption(wifi_setup_screen, "Network", 0);
  wifi_ssid_dd = wizard_dropdown(wifi_setup_screen, 0, 300);
  lv_dropdown_set_options(wifi_ssid_dd, "Scanning...");
  wizard_button(wifi_setup_screen, "Rescan", 330, WIZARD_ROW_Y + 16, 110,
                scheme_accent(), wifi_rescan_cb, NULL);

  wizard_caption(wifi_setup_screen, "Password", 1);
  wifi_pass_ta = wizard_field(wifi_setup_screen, 1, 420, "Enter password", true);

  wizard_button(wifi_setup_screen, "Connect", 20, WIZARD_BTN_Y, 160,
                lv_color_hex(0x00A86B), wifi_connect_cb, NULL);
  // Only offered when there is something to log out of.
  if (!ssid.isEmpty()) {
    wizard_button(wifi_setup_screen, "WiFi Logout", 190, WIZARD_BTN_Y, 160,
                  lv_color_hex(0x333333), wifi_logout_cb, NULL);
  }

  wifi_status_lbl = wizard_status_label(wifi_setup_screen);
  wizard_qr(wifi_setup_screen,
            "Register on crontech.uk to use CronTab.\nScan for quick setup.");

  wifi_scan_start(); // the list fills in when the async scan completes
}


void show_api_code_screen() {
  if (api_code_screen) return; // already up
  if (debug == 1) Serial.println("[APP] Showing API code screen...");
  api_code_screen = wizard_screen("API Code", 2,
      "Enter the API code from your crontech.uk account, or scan the code on the right.");

  wizard_caption(api_code_screen, "API code", 0);
  api_code_ta = wizard_field(api_code_screen, 0, 300, "Enter API code", false);
  if (!apiCode.isEmpty()) lv_textarea_set_text(api_code_ta, apiCode.c_str());
  api_code_status = wizard_status_label(api_code_screen);

  wizard_button(api_code_screen, "Continue", 20, WIZARD_BTN_Y, 160,
                lv_color_hex(0x00A86B), api_code_submit_cb, NULL);
  wizard_button(api_code_screen, "Back", 190, WIZARD_BTN_Y, 160,
                lv_color_hex(0x555555), wizard_back_cb, (void *)(intptr_t)2);

  wizard_qr(api_code_screen, "Scan the QR for a quick API code");
}
void show_location_screen() {
  if (location_screen) return; // already up
  if (debug == 1) Serial.println("[APP] Showing location screen...");
  location_screen = wizard_screen("Weather Location", 3,
      "Which city should the weather come from? It is looked up online, so check the spelling.");

  wizard_caption(location_screen, "City", 0);
  location_ta = wizard_field(location_screen, 0, 420, "e.g. London, UK", false);
  if (!location.isEmpty()) lv_textarea_set_text(location_ta, location.c_str());
  location_status = wizard_status_label(location_screen);

  wizard_button(location_screen, "Save", 20, WIZARD_BTN_Y, 160,
                lv_color_hex(0x00A86B), location_submit_cb, NULL);
  wizard_button(location_screen, "Back", 190, WIZARD_BTN_Y, 160,
                lv_color_hex(0x555555), wizard_back_cb, (void *)(intptr_t)3);

  // Help panel rather than a QR on the last step.
  lv_obj_t *help = lv_label_create(location_screen);
  lv_label_set_text(help,
                    "Write it the way it is normally written:\n\n"
                    "London\n\n"
                    "Manchester, UK\n\n"
                    "Paris, FR");
  lv_obj_set_style_text_font(help, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(help, lv_color_hex(0xFFFFFF), 0);
  lv_label_set_long_mode(help, LV_LABEL_LONG_WRAP);
  lv_obj_set_width(help, 300);
  lv_obj_align(help, LV_ALIGN_TOP_RIGHT, -40, 60);
}
void close_settings_cb(lv_event_t * e) {
  close_confirm_popup(); // never leave a warning dialog orphaned
  if (settings_popup) {
    lv_obj_add_flag(settings_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(settings_popup);
    settings_popup = nullptr;
  }
}
void factory_reset_cb(lv_event_t *e);
void show_settings_popup() {
    // Memory guard kept from the later crash fix: this window costs ~20KB of
    // the LVGL heap, and if that is unavailable lv_obj_create() returns NULL
    // part-way through and the next style call dereferences it.
    lv_mem_monitor_t mem;
    lv_mem_monitor(&mem);
    if (mem.free_size < 28000) {
      if (debug == 1) Serial.printf("[APP] Settings: not enough LVGL memory (%u free)\n", (unsigned)mem.free_size);
      return;
    }
    if (debug == 1) Serial.println("[APP] Showing settings popup...");
    settings_popup = lv_obj_create(lv_scr_act());
    if (!settings_popup) return;
    lv_obj_set_size(settings_popup, SETTINGS_POPUP_W, SETTINGS_POPUP_H);
    lv_obj_align(settings_popup, LV_ALIGN_CENTER, 0, 0);
    lv_obj_set_style_bg_color(settings_popup, lv_color_hex(0x000000), 0);
    lv_obj_set_style_border_color(settings_popup, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_border_width(settings_popup, 2, 0);
    lv_obj_set_style_radius(settings_popup, 10, 0);
    lv_obj_set_style_pad_all(settings_popup, 10, 0);
    lv_obj_set_style_pad_row(settings_popup, 8, 0);
    // Plain vertical scrolling with a visible, easy-to-grab scrollbar.
    // Scroll snapping was removed: it made the page jump while dragging.
    lv_obj_set_scroll_dir(settings_popup, LV_DIR_VER);
    lv_obj_set_scrollbar_mode(settings_popup, LV_SCROLLBAR_MODE_AUTO);
    lv_obj_set_style_width(settings_popup, 10, LV_PART_SCROLLBAR);
    lv_obj_set_style_bg_color(settings_popup, lv_color_hex(0xBBBBBB), LV_PART_SCROLLBAR);
    lv_obj_set_flex_flow(settings_popup, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_flex_align(settings_popup, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START);

    // ---- small layout helpers --------------------------------------------
    auto make_panel = [](lv_obj_t *parent) {
      lv_obj_t *o = lv_obj_create(parent);
      lv_obj_set_style_bg_opa(o, LV_OPA_TRANSP, 0);
      lv_obj_set_style_border_width(o, 0, 0);
      lv_obj_set_style_pad_all(o, 0, 0);
      lv_obj_clear_flag(o, LV_OBJ_FLAG_SCROLLABLE);
      lv_obj_set_scrollbar_mode(o, LV_SCROLLBAR_MODE_OFF);
      return o;
    };
    // Takes lv_color_t rather than raw hex so the section cards can follow the
    // active colour scheme. All three use mid-to-dark accent shades because
    // make_card_title() draws the headings in white.
    auto make_card = [](lv_obj_t *parent, lv_color_t bg, lv_color_t grad) {
      lv_obj_t *card = lv_obj_create(parent);
      lv_obj_set_width(card, LV_PCT(100));
      lv_obj_set_height(card, LV_SIZE_CONTENT);
      lv_obj_set_style_bg_color(card, bg, 0);
      lv_obj_set_style_bg_grad_color(card, grad, 0);
      lv_obj_set_style_bg_grad_dir(card, LV_GRAD_DIR_HOR, 0);
      lv_obj_set_style_radius(card, 8, 0);
      lv_obj_set_style_pad_all(card, 6, 0);
      lv_obj_set_style_pad_row(card, 4, 0);
      lv_obj_set_flex_flow(card, LV_FLEX_FLOW_COLUMN);
      lv_obj_clear_flag(card, LV_OBJ_FLAG_SCROLLABLE);
      lv_obj_set_scrollbar_mode(card, LV_SCROLLBAR_MODE_OFF);
      return card;
    };
    auto make_card_title = [](lv_obj_t *card, const char *text) {
      lv_obj_t *lbl = lv_label_create(card);
      lv_label_set_text(lbl, text);
      lv_obj_set_style_text_font(lbl, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(lbl, lv_color_hex(0xFFFFFF), 0);
      return lbl;
    };
    // Field = input box with its description label UNDERNEATH it. `width` sizes
    // the column; pass width 0 + grow 1 to share a row with another field.
    auto make_field = [](lv_obj_t *parent, const char *label_text, const char *placeholder,
                         const String &value, intptr_t numeric, lv_coord_t width, uint8_t grow) {
      lv_obj_t *col = lv_obj_create(parent);
      lv_obj_set_width(col, width);
      if (grow) lv_obj_set_flex_grow(col, grow);
      lv_obj_set_height(col, LV_SIZE_CONTENT);
      lv_obj_set_style_bg_opa(col, LV_OPA_TRANSP, 0);
      lv_obj_set_style_border_width(col, 0, 0);
      lv_obj_set_style_pad_all(col, 0, 0);
      lv_obj_set_style_pad_row(col, 2, 0);
      lv_obj_set_flex_flow(col, LV_FLEX_FLOW_COLUMN);
      lv_obj_set_flex_align(col, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START);
      lv_obj_clear_flag(col, LV_OBJ_FLAG_SCROLLABLE);
      lv_obj_set_scrollbar_mode(col, LV_SCROLLBAR_MODE_OFF);
      lv_obj_t *ta = lv_textarea_create(col);
      lv_textarea_set_one_line(ta, true);
      lv_textarea_set_placeholder_text(ta, placeholder);
      lv_textarea_set_text(ta, value.c_str());
      lv_obj_set_width(ta, LV_PCT(100));
      lv_obj_set_style_text_font(ta, &lv_font_montserrat_14, 0);
      lv_obj_set_user_data(ta, (void*)numeric);
      lv_obj_add_event_cb(ta, keyboard_event_cb, LV_EVENT_FOCUSED, ta);
      lv_obj_add_event_cb(ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ta);
      lv_obj_t *label = lv_label_create(col); // description below the input
      lv_label_set_text(label, label_text);
      lv_obj_set_style_text_font(label, &lv_font_montserrat_14, 0);
      lv_obj_set_style_text_color(label, lv_color_hex(0xFFFFFF), 0);
      return ta;
    };
    auto make_button = [](lv_obj_t *parent, const char *text, uint32_t color, lv_coord_t w) {
      lv_obj_t *btn = lv_button_create(parent);
      lv_obj_set_size(btn, w, 42);
      lv_obj_set_style_bg_color(btn, lv_color_hex(color), 0);
      lv_obj_t *label = lv_label_create(btn);
      lv_label_set_text(label, text);
      lv_obj_center(label);
      lv_obj_set_style_text_font(label, &lv_font_montserrat_14, 0);
      return btn;
    };

    // (No "Settings" heading: it only pushed the content down.)

    // ---- two-column body --------------------------------------------------
    lv_obj_t *settings_body = make_panel(settings_popup);
    lv_obj_set_width(settings_body, LV_PCT(100));
    lv_obj_set_height(settings_body, LV_SIZE_CONTENT);
    lv_obj_set_style_pad_column(settings_body, 10, 0);
    lv_obj_set_flex_flow(settings_body, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(settings_body, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START);

    lv_obj_t *left_col = make_panel(settings_body);
    lv_obj_set_width(left_col, 0);
    lv_obj_set_height(left_col, LV_SIZE_CONTENT);
    lv_obj_set_flex_grow(left_col, 1);
    lv_obj_set_style_pad_row(left_col, 8, 0);
    lv_obj_set_flex_flow(left_col, LV_FLEX_FLOW_COLUMN);

    lv_obj_t *right_col = make_panel(settings_body);
    lv_obj_set_width(right_col, 0);
    lv_obj_set_height(right_col, LV_SIZE_CONTENT);
    lv_obj_set_flex_grow(right_col, 1);
    lv_obj_set_style_pad_row(right_col, 8, 0);
    lv_obj_set_flex_flow(right_col, LV_FLEX_FLOW_COLUMN);

    // Left column: just the QR code now that brightness has moved across.
    lv_obj_t *qr_card = make_card(left_col, lv_color_hex(0x151515), lv_color_hex(0x151515));
    lv_obj_set_flex_align(qr_card, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
    lv_obj_t *qr_img = lv_img_create(qr_card);
    lv_img_set_src(qr_img, &qr);
    lv_img_set_zoom(qr_img, 132); // 20% up from 110; 298px source -> ~154px on screen
    lv_obj_t *qr_hint = lv_label_create(qr_card);
    lv_label_set_text(qr_hint, "Scan to open the web app");
    lv_obj_set_style_text_font(qr_hint, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(qr_hint, lv_color_hex(0xFFFFFF), 0);

    // Right column: weather location, ParcelBox, brightness.
    lv_obj_t *weather_cont = make_card(right_col, scheme_accent(), scheme_accent_dark());
    make_card_title(weather_cont, "Weather Location");
    lv_obj_t *location_ta = make_field(weather_cont, "City", "City", location, 0, LV_PCT(100), 0);

    lv_obj_t *parcelbox_cont = make_card(right_col, scheme_accent_dark(), scheme_accent_deep());
    make_card_title(parcelbox_cont, "ParcelBox");
    // Username and Device ID side by side, each with its description underneath.
    // (The old Device ID "Preview" row was removed.)
    lv_obj_t *parcel_pair = make_panel(parcelbox_cont);
    lv_obj_set_width(parcel_pair, LV_PCT(100));
    lv_obj_set_height(parcel_pair, LV_SIZE_CONTENT);
    lv_obj_set_style_pad_column(parcel_pair, 8, 0);
    lv_obj_set_flex_flow(parcel_pair, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(parcel_pair, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START, LV_FLEX_ALIGN_START);
    lv_obj_t *username_ta = make_field(parcel_pair, "Username", "Username", username, 0, 0, 1);
    lv_obj_t *device_id_ta = make_field(parcel_pair, "Device ID", "Device ID", device_id, 0, 0, 1);

    // UI Brightness now occupies the slot the "Temperature Adjustment" card used
    // to hold, so both columns stay full-height instead of leaving a gap.
    lv_obj_t *brightness_card = make_card(right_col, lv_color_hex(0x1e1e1e), lv_color_hex(0x1e1e1e));
    make_card_title(brightness_card, "UI Brightness");
    lv_obj_t *darkness_slider = lv_slider_create(brightness_card);
    lv_slider_set_range(darkness_slider, 0, 100);
    lv_slider_set_value(darkness_slider, g_ui_darkness, LV_ANIM_OFF);
    lv_obj_set_width(darkness_slider, LV_PCT(100));
    lv_obj_add_event_cb(darkness_slider, darkness_slider_cb, LV_EVENT_VALUE_CHANGED, NULL);

    // Firmware version sits directly under the last card as a normal child of the
    // column, so it starts at the same left edge as that card rather than
    // floating in a corner.
    lv_obj_t *version_settings_label = lv_label_create(right_col);
    lv_label_set_text(version_settings_label, ("Firmware: " + currentFirmwareVersion).c_str());
    lv_obj_set_style_text_font(version_settings_label, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(version_settings_label, lv_color_hex(0xDDDDDD), 0);

    // ---- footer buttons ---------------------------------------------------
    lv_obj_t *settings_footer = make_panel(settings_popup);
    lv_obj_set_width(settings_footer, LV_PCT(100));
    lv_obj_set_height(settings_footer, LV_SIZE_CONTENT);
    lv_obj_set_style_pad_column(settings_footer, 8, 0);
    lv_obj_set_flex_flow(settings_footer, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(settings_footer, LV_FLEX_ALIGN_SPACE_BETWEEN, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);

    lv_obj_t *wifi_logout_btn = make_button(settings_footer, "WiFi Logout", 0x333333, 110);
    lv_obj_add_event_cb(wifi_logout_btn, wifi_logout_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_t *api_logout_btn = make_button(settings_footer, "API Logout", 0x333333, 110);
    lv_obj_add_event_cb(api_logout_btn, api_logout_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_t *factory_reset_btn = make_button(settings_footer, "Factory Reset", 0x8e0000, 130);
    lv_obj_add_event_cb(factory_reset_btn, factory_reset_cb, LV_EVENT_PRESSED, NULL);
    lv_obj_t *submit_btn = make_button(settings_footer, "Save", 0x007aff, 110);
    lv_obj_t *close_btn = make_button(settings_footer, "Close", 0xff0000, 110);
    lv_obj_add_event_cb(close_btn, close_settings_cb, LV_EVENT_PRESSED, NULL);

    SettingsUI *sui = new SettingsUI();
    sui->location_ta = location_ta;
    sui->username_ta = username_ta;
    sui->device_id_ta = device_id_ta;
    lv_obj_add_event_cb(submit_btn, save_settings_cb, LV_EVENT_PRESSED, sui);

    // Keyboard setup (shared across screens, hidden until a field is focused).
    if (!keyboard) {
      keyboard = lv_keyboard_create(lv_scr_act());
      lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
      lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
    }
}
static void do_factory_reset() {
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
void factory_reset_cb(lv_event_t *e) {
  show_confirm_popup("Factory Reset",
                     "This erases ALL settings (WiFi, API code, location, brightness, parcel box and reminder state) and restarts the device. This cannot be undone.",
                     "Reset", do_factory_reset);
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
// ---- Add-event form layout helpers -----------------------------------------
// The form is two columns: free text on the left, date/time on the right, with
// the action buttons pinned to the window's bottom-right corner so they stay
// inside it whatever height it ends up with.
static lv_obj_t *new_event_caption(lv_obj_t *parent, const char *text,
                                   lv_coord_t x, lv_coord_t y) {
  lv_obj_t *l = lv_label_create(parent);
  lv_label_set_text(l, text);
  lv_obj_align(l, LV_ALIGN_TOP_LEFT, x, y);
  lv_obj_set_style_text_font(l, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(l, lv_color_hex(0xFFFFFF), 0);
  return l;
}
static void new_event_hint(lv_obj_t *parent, const char *text, lv_coord_t x, lv_coord_t y) {
  lv_obj_t *l = new_event_caption(parent, text, x, y);
  lv_obj_set_style_text_color(l, lv_color_hex(0xAAAAAA), 0);
}
// One prefilled field wired to the shared on-screen keyboard. `numeric` selects
// the number keypad for the date/time boxes (same flag the old inline code put
// in lv_obj_set_user_data).
static lv_obj_t *new_event_field(lv_obj_t *parent, const char *text, const char *placeholder,
                                 uint32_t max_len, bool numeric, lv_coord_t x, lv_coord_t y,
                                 lv_coord_t w, lv_coord_t h) {
  lv_obj_t *ta = lv_textarea_create(parent);
  lv_textarea_set_one_line(ta, true);
  lv_textarea_set_max_length(ta, max_len);
  lv_textarea_set_placeholder_text(ta, placeholder);
  lv_textarea_set_text(ta, text);
  lv_obj_set_size(ta, w, h);
  lv_obj_align(ta, LV_ALIGN_TOP_LEFT, x, y);
  lv_obj_set_style_text_font(ta, &lv_font_montserrat_14, 0);
  // Deliberately NO text colour here: the LVGL theme picks one that contrasts
  // with the textarea background it also draws, which is exactly what
  // make_field() does in Settings. Pinning this to a dark grey made the
  // pre-filled values unreadable in dark mode, where the theme draws a dark
  // textarea (apply_theme_accent() passes dark = g_ui_darkness > 50).
  lv_obj_set_style_pad_all(ta, 4, 0);
  lv_obj_set_user_data(ta, (void *)(intptr_t)(numeric ? 1 : 0));
  lv_obj_add_event_cb(ta, keyboard_event_cb, LV_EVENT_FOCUSED, ta);
  lv_obj_add_event_cb(ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ta);
  return ta;
}
// Show/replace the one inline error message on the form. Reusing a single label
// stops the old code from stacking a fresh red label on every failed submit.
static void new_event_error(const char *msg) {
  if (!new_event_popup) return;
  if (new_event_error_label) {
    lv_obj_del(new_event_error_label);
    new_event_error_label = nullptr;
  }
  new_event_error_label = lv_label_create(new_event_popup);
  lv_label_set_text(new_event_error_label, msg);
  lv_obj_set_style_text_color(new_event_error_label, lv_color_hex(0xFF0000), 0);
  lv_obj_set_style_text_font(new_event_error_label, &lv_font_montserrat_14, 0);
  lv_obj_align(new_event_error_label, LV_ALIGN_BOTTOM_MID, 0, -46);
}
// Single teardown path for the add-event window so the keyboard never stays on
// screen over the calendar after Submit/Cancel.
static void close_new_event_popup() {
  if (keyboard) {
    lv_keyboard_set_textarea(keyboard, nullptr);
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  }
  new_event_error_label = nullptr; // it dies with the window
  if (new_event_popup) {
    lv_obj_add_flag(new_event_popup, LV_OBJ_FLAG_HIDDEN);
    lv_obj_del(new_event_popup);
    new_event_popup = nullptr;
  }
}
void new_event_btn_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] New event button clicked");
  show_new_event_popup();
}
void show_new_event_popup(lv_calendar_date_t *selected_date) {
  // Only one add-event window at a time. new_event_submit_cb / new_event_cancel_cb
  // act on the single `new_event_popup` global, so opening a second window would
  // orphan the first one and leave its buttons pointing at a stale (or NULL)
  // pointer - pressing those then dereferences it and resets the device.
  if (new_event_popup) {
    if (debug == 1) Serial.println("[APP] New event popup already open, ignoring");
    return;
  }
  close_day_events_popup();

  // --- Make sure the keyboard exists, then measure it ------------------------
  // The form is laid out in whatever vertical strip is left above the keyboard.
  // Measuring the real height instead of assuming one is what stops the window
  // from being covered: the old 600x400 window centred at y=40..440 sat
  // directly underneath a keyboard occupying the bottom ~200px, and the
  // "shift the parent up by the overlap" fallback then walked it off the top of
  // the screen.
  if (!keyboard) {
    keyboard = lv_keyboard_create(lv_scr_act());
    lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
    lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_text_font(keyboard, &lv_font_montserrat_14, 0);
  }
  // A hidden object is not laid out, so unhide -> relayout -> measure -> re-hide.
  // No lv_timer_handler() runs in between, so the keyboard is never painted.
  bool kb_was_hidden = lv_obj_has_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_clear_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
  lv_obj_set_width(keyboard, LV_PCT(100));
  lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
  lv_obj_update_layout(keyboard);
  lv_coord_t kb_h = lv_obj_get_height(keyboard);
  if (kb_h < 80 || kb_h > 300) kb_h = 190; // unexpected -> sane default
  if (kb_was_hidden) lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);

  lv_coord_t scr_h = lv_disp_get_ver_res(lv_disp_get_default());
  lv_coord_t popup_h = scr_h - kb_h - NEW_EVENT_TOP - 4;
  if (popup_h < 150) popup_h = 150; // never collapse to nothing

  new_event_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(new_event_popup, NEW_EVENT_W, popup_h);
  lv_obj_align(new_event_popup, LV_ALIGN_TOP_MID, 0, NEW_EVENT_TOP);
  lv_obj_set_style_bg_color(new_event_popup, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_color(new_event_popup, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_border_width(new_event_popup, 2, 0);
  lv_obj_set_style_radius(new_event_popup, 8, 0);
  lv_obj_set_style_pad_all(new_event_popup, 10, 0);
  lv_obj_set_scrollbar_mode(new_event_popup, LV_SCROLLBAR_MODE_AUTO);
  new_event_error_label = nullptr;
  NewEventUI *ui = new NewEventUI();
  new_event_caption(new_event_popup, "Title", 0, 0);
  ui->title_ta = new_event_field(new_event_popup, "", "Event title", 128, false,
                                 0, 20, 320, NEW_EVENT_ROW);
  lv_obj_add_event_cb(ui->title_ta, keyboard_event_cb, LV_EVENT_FOCUSED, ui->title_ta);
  lv_obj_add_event_cb(ui->title_ta, keyboard_event_cb, LV_EVENT_DEFOCUSED, ui->title_ta);
  new_event_caption(new_event_popup, "Description", 0, 60);
  ui->desc_ta = lv_textarea_create(new_event_popup);
  lv_textarea_set_one_line(ui->desc_ta, false);
  lv_textarea_set_placeholder_text(ui->desc_ta, "Event description");
  lv_obj_set_size(ui->desc_ta, 320, 52);
  lv_obj_align(ui->desc_ta, LV_ALIGN_TOP_LEFT, 0, 80);
  lv_obj_set_style_text_font(ui->desc_ta, &lv_font_montserrat_14, 0);
  // Theme-provided text colour; see new_event_field() above.
  lv_obj_set_style_pad_all(ui->desc_ta, 4, 0);
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
  new_event_caption(new_event_popup, "Remind before", 0, 140);
  ui->remind_before_ta = new_event_field(new_event_popup, "0m", "0m", 4, false,
                                         0, 160, 90, NEW_EVENT_ROW);
  new_event_hint(new_event_popup, "(15m, 2h, 1d)", 100, 168);
  // Right column: start date/time, grouped as YYYY/MM/DD  HH:MM.
  char buf[5];
  new_event_caption(new_event_popup, "Start", NEW_EVENT_COL2_X, 0);
  snprintf(buf, sizeof(buf), "%04d", start_year);
  ui->start_year_ta = new_event_field(new_event_popup, buf, "YYYY", 4, true,
                                      NEW_EVENT_COL2_X, 20, 74, NEW_EVENT_FIELD_H);
  new_event_hint(new_event_popup, "/", NEW_EVENT_COL2_X + 76, 28);
  snprintf(buf, sizeof(buf), "%02d", start_month);
  ui->start_month_ta = new_event_field(new_event_popup, buf, "MM", 2, true,
                                       NEW_EVENT_COL2_X + 88, 20, 48, NEW_EVENT_FIELD_H);
  new_event_hint(new_event_popup, "/", NEW_EVENT_COL2_X + 138, 28);
  snprintf(buf, sizeof(buf), "%02d", start_day);
  ui->start_day_ta = new_event_field(new_event_popup, buf, "DD", 2, true,
                                     NEW_EVENT_COL2_X + 150, 20, 48, NEW_EVENT_FIELD_H);
  snprintf(buf, sizeof(buf), "%02d", start_hour);
  ui->start_hour_ta = new_event_field(new_event_popup, buf, "HH", 2, true,
                                      NEW_EVENT_COL2_X + 218, 20, 48, NEW_EVENT_FIELD_H);
  new_event_hint(new_event_popup, ":", NEW_EVENT_COL2_X + 268, 28);
  snprintf(buf, sizeof(buf), "%02d", start_min);
  ui->start_min_ta = new_event_field(new_event_popup, buf, "MM", 2, true,
                                     NEW_EVENT_COL2_X + 280, 20, 48, NEW_EVENT_FIELD_H);
  new_event_caption(new_event_popup, "End", NEW_EVENT_COL2_X, 60);
  snprintf(buf, sizeof(buf), "%04d", end_year);
  ui->end_year_ta = new_event_field(new_event_popup, buf, "YYYY", 4, true,
                                    NEW_EVENT_COL2_X, 80, 74, NEW_EVENT_FIELD_H);
  new_event_hint(new_event_popup, "/", NEW_EVENT_COL2_X + 76, 88);
  snprintf(buf, sizeof(buf), "%02d", end_month);
  ui->end_month_ta = new_event_field(new_event_popup, buf, "MM", 2, true,
                                     NEW_EVENT_COL2_X + 88, 80, 48, NEW_EVENT_FIELD_H);
  new_event_hint(new_event_popup, "/", NEW_EVENT_COL2_X + 138, 88);
  snprintf(buf, sizeof(buf), "%02d", end_day);
  ui->end_day_ta = new_event_field(new_event_popup, buf, "DD", 2, true,
                                   NEW_EVENT_COL2_X + 150, 80, 48, NEW_EVENT_FIELD_H);
  snprintf(buf, sizeof(buf), "%02d", end_hour);
  ui->end_hour_ta = new_event_field(new_event_popup, buf, "HH", 2, true,
                                    NEW_EVENT_COL2_X + 218, 80, 48, NEW_EVENT_FIELD_H);
  new_event_hint(new_event_popup, ":", NEW_EVENT_COL2_X + 268, 88);
  snprintf(buf, sizeof(buf), "%02d", end_min);
  ui->end_min_ta = new_event_field(new_event_popup, buf, "MM", 2, true,
                                   NEW_EVENT_COL2_X + 280, 80, 48, NEW_EVENT_FIELD_H);
  lv_obj_t *submit_btn = lv_button_create(new_event_popup);
  lv_obj_set_size(submit_btn, 120, 38);
  lv_obj_align(submit_btn, LV_ALIGN_BOTTOM_RIGHT, -134, -2);
  lv_obj_set_style_bg_color(submit_btn, lv_color_hex(0x00FF00), 0);
  lv_obj_add_event_cb(submit_btn, new_event_submit_cb, LV_EVENT_PRESSED, ui);
  lv_obj_t *submit_label = lv_label_create(submit_btn);
  lv_label_set_text(submit_label, "Submit");
  lv_obj_center(submit_label);
  lv_obj_set_style_text_font(submit_label, &lv_font_montserrat_14, 0);
  lv_obj_t *cancel_btn = lv_button_create(new_event_popup);
  lv_obj_set_size(cancel_btn, 120, 38);
  lv_obj_align(cancel_btn, LV_ALIGN_BOTTOM_RIGHT, -4, -2);
  lv_obj_set_style_bg_color(cancel_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_add_event_cb(cancel_btn, new_event_cancel_cb, LV_EVENT_PRESSED, ui);
  lv_obj_t *cancel_label = lv_label_create(cancel_btn);
  lv_label_set_text(cancel_label, "Cancel");
  lv_obj_center(cancel_label);
  lv_obj_set_style_text_font(cancel_label, &lv_font_montserrat_14, 0);
}
void new_event_submit_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] New event submit button clicked");
  NewEventUI *ui = (NewEventUI*)lv_event_get_user_data(e);
  if (!new_event_popup) {
    // Window is already gone (stale button press): drop the UI state instead of
    // dereferencing a deleted/NULL object.
    if (debug == 1) Serial.println("[APP] Submit ignored: popup already closed");
    if (ui) delete ui;
    return;
  }
  if (!ui) {
    if (debug == 1) Serial.println("[APP] Error: UI structure is null");
    new_event_error("Internal error");
    return;
  }
  if (!ui->title_ta || !ui->desc_ta || !ui->start_year_ta || !ui->start_month_ta ||
      !ui->start_day_ta || !ui->start_hour_ta || !ui->start_min_ta ||
      !ui->end_year_ta || !ui->end_month_ta || !ui->end_day_ta ||
      !ui->end_hour_ta || !ui->end_min_ta || !ui->remind_before_ta) {
    if (debug == 1) Serial.println("[APP] Error: One or more UI elements are null");
    new_event_error("Internal error");
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
    new_event_error("Invalid date/time");
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
    new_event_error(error_message.c_str());
    http.end();
    return;
  }
  http.end();
  delete ui;
  close_new_event_popup();
}
void new_event_cancel_cb(lv_event_t * e) {
  if (debug == 1) Serial.println("[APP] New event cancel button clicked");
  NewEventUI *ui = (NewEventUI*)lv_event_get_user_data(e);
  if (ui) {
    delete ui;
  }
  close_new_event_popup();
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
// Modified update_btn_cb function
// ---- OTA firmware update ---------------------------------------------------
// The download is pumped from loop() in bounded slices, so the screen keeps
// refreshing and the progress bar actually moves.
//
// The previous implementation did the whole transfer inside the "Update Now"
// button callback and called loop_display() - that is lv_task_handler() - from in
// there to repaint. LVGL is not re-entrant, so the display was being refreshed
// from inside its own event dispatch (that is what made it jump), and for the
// rest of the download, which is by far the longest part, nothing repainted it at
// all (that is what made it freeze).
static HTTPClient otaHttp;
static WiFiClient *otaStream = nullptr;
static bool otaHttpActive = false;
static bool otaDownloading = false;
static size_t otaWritten = 0;
static size_t otaTotal = 0;
static unsigned long otaLastUiTick = 0;
static lv_obj_t *ota_status_lbl = nullptr;
static lv_obj_t *ota_bar = nullptr;
static lv_obj_t *ota_action_btn = nullptr;
static lv_obj_t *ota_close_btn = nullptr;

static void ota_set_status(const char *msg, bool is_error) {
  if (!ota_status_lbl) return;
  lv_label_set_text(ota_status_lbl, msg);
  lv_obj_set_style_text_color(ota_status_lbl, lv_color_hex(is_error ? 0xFF5252 : 0xFFFFFF), 0);
}
static void ota_close_transfer() {
  if (otaHttpActive) {
    otaHttp.end();
    otaHttpActive = false;
  }
  otaStream = nullptr;
  otaDownloading = false;
  is_ota_updating = false;
}
// Back to a state the user can retry from, with the reason on screen.
static void ota_fail(const char *msg) {
  ota_close_transfer();
  ota_set_status(msg, true);
  if (ota_bar) lv_bar_set_value(ota_bar, 0, LV_ANIM_OFF);
  if (ota_action_btn) {
    lv_obj_t *l = lv_obj_get_child(ota_action_btn, 0);
    if (l) lv_label_set_text(l, "Retry");
    lv_obj_clear_flag(ota_action_btn, LV_OBJ_FLAG_HIDDEN);
  }
  if (ota_close_btn) lv_obj_clear_flag(ota_close_btn, LV_OBJ_FLAG_HIDDEN);
}
static void ota_show_progress(bool force) {
  if (!force && millis() - otaLastUiTick < 200) return; // 5 Hz is plenty
  otaLastUiTick = millis();
  int pct = (otaTotal > 0) ? (int)((otaWritten * 100) / otaTotal) : 0;
  if (ota_bar) lv_bar_set_value(ota_bar, pct, LV_ANIM_OFF);
  if (ota_status_lbl) {
    // Fixed buffer: no Arduino String churn in a per-slice hot path.
    char buf[72];
    snprintf(buf, sizeof(buf), "Downloading... %d%%   (%u / %u KB)",
             pct, (unsigned)(otaWritten / 1024), (unsigned)(otaTotal / 1024));
    lv_label_set_text(ota_status_lbl, buf);
  }
}
static void ota_start() {
  if (otaDownloading) return;
  if (WiFi.status() != WL_CONNECTED) { ota_fail("WiFi is not connected"); return; }
  if (firmwareUrl.isEmpty()) { ota_fail("No update URL was advertised"); return; }

  ota_set_status("Contacting the update server...", false);
  if (ota_action_btn) lv_obj_add_flag(ota_action_btn, LV_OBJ_FLAG_HIDDEN);
  if (ota_close_btn) lv_obj_add_flag(ota_close_btn, LV_OBJ_FLAG_HIDDEN);
  is_ota_updating = true;
  lv_refr_now(NULL); // paint the status before the blocking connect

  client.setInsecure(); // For testing; use a CA certificate in production
  otaHttp.begin(client, firmwareUrl);
  otaHttpActive = true;
  int httpCode = otaHttp.GET();
  if (httpCode != HTTP_CODE_OK) { ota_fail("Could not download the firmware (server error)"); return; }
  int len = otaHttp.getSize();
  if (len <= 0) { ota_fail("The server did not report a size"); return; }
  otaTotal = (size_t)len;
  otaWritten = 0;
  if (!Update.begin(otaTotal)) { ota_fail("Not enough flash space for the update"); return; }
  otaStream = otaHttp.getStreamPtr();
  if (!otaStream) { ota_fail("Could not open the download stream"); return; }
  if (ota_bar) {
    lv_bar_set_range(ota_bar, 0, 100);
    lv_bar_set_value(ota_bar, 0, LV_ANIM_OFF);
  }
  otaDownloading = true;
  ota_show_progress(true);
}
// One bounded slice per loop() iteration.
static void serviceOta() {
  if (!otaDownloading || !otaStream) return;
  uint8_t buff[512];
  int budget = 8; // up to 4KB per pass, then hand the CPU back to LVGL
  while (budget-- > 0 && otaWritten < otaTotal) {
    size_t avail = otaStream->available();
    if (!avail) { delay(1); break; } // nothing buffered: let WiFi and LVGL run
    int c = otaStream->readBytes(buff, min(sizeof(buff), avail));
    if (c <= 0) break;
    if (Update.write(buff, c) != (size_t)c) {
      Update.abort();
      ota_fail("Writing the firmware to flash failed");
      return;
    }
    otaWritten += c;
  }
  ota_show_progress(false);
  if (otaWritten < otaTotal) return;

  // ---- download finished ----
  otaHttp.end();
  otaHttpActive = false;
  otaDownloading = false;
  otaStream = nullptr;
  if (ota_bar) lv_bar_set_value(ota_bar, 100, LV_ANIM_OFF);
  if (!Update.end(true)) { ota_fail("Could not finalise the update"); return; }
  preferences.begin("firmware", false);
  preferences.putString("version", latestFirmwareVersion);
  preferences.end();
  ota_set_status("Update complete. Restarting...", false);
  is_ota_updating = false;
  lv_refr_now(NULL); // make sure the user sees it before the reboot
  delay(1500);
  ESP.restart();
}
static void ota_action_cb(lv_event_t *e) {
  (void)e;
  ota_start(); // the same button serves as Update Now and Retry
}
static void ota_close_cb(lv_event_t *e) {
  (void)e;
  if (otaDownloading) return; // never close mid-flash
  if (update_popup) {
    lv_obj_del(update_popup);
    update_popup = nullptr;
  }
  ota_status_lbl = nullptr;
  ota_bar = nullptr;
  ota_action_btn = nullptr;
  ota_close_btn = nullptr;
}
void update_btn_cb(lv_event_t *e) {
  (void)e;
  if (debug == 1) Serial.println("[OTA] Update button clicked");
  if (update_popup) return;
  // NOTE: the old code hid firmware_update_btn here and only ever unhid it on
  // success - and success reboots - so a failed update left the user with no
  // button and no explanation. The popup covers it, so it is left alone.
  update_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(update_popup, 620, 300);
  lv_obj_align(update_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(update_popup, lv_color_hex(0x1A1A1A), 0);
  lv_obj_set_style_border_color(update_popup, scheme_accent(), 0);
  lv_obj_set_style_border_width(update_popup, 3, 0);
  lv_obj_set_style_radius(update_popup, 10, 0);
  lv_obj_set_style_pad_all(update_popup, 16, 0);
  lv_obj_set_style_pad_row(update_popup, 12, 0);
  lv_obj_set_flex_flow(update_popup, LV_FLEX_FLOW_COLUMN);
  lv_obj_set_flex_align(update_popup, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER,
                        LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(update_popup, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(update_popup, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *ota_title = lv_label_create(update_popup);
  lv_label_set_text(ota_title, LV_SYMBOL_DOWNLOAD "  Firmware Update");
  lv_obj_set_style_text_font(ota_title, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(ota_title, scheme_accent(), 0);

  lv_obj_t *ota_ver = lv_label_create(update_popup);
  lv_label_set_text_fmt(ota_ver, "Installed: %s      New: %s",
                        currentFirmwareVersion.c_str(),
                        latestFirmwareVersion.isEmpty() ? "unknown"
                                                        : latestFirmwareVersion.c_str());
  lv_obj_set_style_text_font(ota_ver, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(ota_ver, lv_color_hex(0xAAAAAA), 0);

  ota_bar = lv_bar_create(update_popup);
  lv_obj_set_size(ota_bar, 540, 18);
  lv_bar_set_range(ota_bar, 0, 100);
  lv_bar_set_value(ota_bar, 0, LV_ANIM_OFF);
  lv_obj_set_style_bg_color(ota_bar, lv_color_hex(0x333333), LV_PART_MAIN);
  lv_obj_set_style_bg_color(ota_bar, scheme_accent(), LV_PART_INDICATOR);

  ota_status_lbl = lv_label_create(update_popup);
  lv_label_set_text(ota_status_lbl, "Ready to update. Keep the device powered on.");
  lv_obj_set_style_text_font(ota_status_lbl, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(ota_status_lbl, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_width(ota_status_lbl, LV_PCT(100));
  lv_obj_set_style_text_align(ota_status_lbl, LV_TEXT_ALIGN_CENTER, 0);
  lv_label_set_long_mode(ota_status_lbl, LV_LABEL_LONG_WRAP);

  lv_obj_t *ota_row = lv_obj_create(update_popup);
  lv_obj_set_size(ota_row, LV_PCT(100), LV_SIZE_CONTENT);
  lv_obj_set_style_bg_opa(ota_row, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(ota_row, 0, 0);
  lv_obj_set_style_pad_all(ota_row, 0, 0);
  lv_obj_set_style_pad_column(ota_row, 16, 0);
  lv_obj_set_flex_flow(ota_row, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(ota_row, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER,
                        LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(ota_row, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(ota_row, LV_SCROLLBAR_MODE_OFF);

  ota_action_btn = lv_button_create(ota_row);
  lv_obj_set_size(ota_action_btn, 180, 46);
  lv_obj_set_style_bg_color(ota_action_btn, lv_color_hex(0x00A86B), 0);
  lv_obj_set_style_radius(ota_action_btn, 10, 0);
  lv_obj_add_event_cb(ota_action_btn, ota_action_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *ota_al = lv_label_create(ota_action_btn);
  lv_label_set_text(ota_al, "Update Now");
  lv_obj_center(ota_al);
  lv_obj_set_style_text_font(ota_al, &lv_font_montserrat_14, 0);

  ota_close_btn = lv_button_create(ota_row);
  lv_obj_set_size(ota_close_btn, 140, 46);
  lv_obj_set_style_bg_color(ota_close_btn, lv_color_hex(0x555555), 0);
  lv_obj_set_style_radius(ota_close_btn, 10, 0);
  lv_obj_add_event_cb(ota_close_btn, ota_close_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *ota_cl = lv_label_create(ota_close_btn);
  lv_label_set_text(ota_cl, "Close");
  lv_obj_center(ota_cl);
  lv_obj_set_style_text_font(ota_cl, &lv_font_montserrat_14, 0);
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
// Colours for the calendar grid, derived from the UI brightness setting so the
// calendar stays readable in both light and dark modes. Called at setup and
// whenever the brightness slider moves.
void apply_calendar_theme(lv_obj_t *cal) {
  if (!cal) return;
  const bool dark = (g_ui_darkness > 50);
  const lv_color_t card_bg    = dark ? lv_color_hex(0x1B1B1B) : lv_color_hex(0xFFFFFF);
  const lv_color_t card_line  = dark ? lv_color_hex(0x333333) : lv_color_hex(0xD8DEE4);
  const lv_color_t cell_bg    = dark ? lv_color_hex(0x262626) : lv_color_hex(0xF2F5F8);
  const lv_color_t cell_press = dark ? scheme_accent_deep() : scheme_accent_soft();
  const lv_color_t text       = dark ? lv_color_hex(0xECEFF1) : lv_color_hex(0x2C3E50);

  lv_obj_set_style_bg_color(cal, card_bg, LV_PART_MAIN);
  lv_obj_set_style_bg_opa(cal, LV_OPA_COVER, LV_PART_MAIN);
  lv_obj_set_style_border_color(cal, card_line, LV_PART_MAIN);
  // Day cells
  lv_obj_set_style_bg_color(cal, cell_bg, LV_PART_ITEMS);
  lv_obj_set_style_bg_opa(cal, LV_OPA_COVER, LV_PART_ITEMS);
  lv_obj_set_style_bg_color(cal, cell_press, LV_PART_ITEMS | LV_STATE_PRESSED);
  lv_obj_set_style_text_color(cal, text, LV_PART_ITEMS);
  // The 1px item border must stay opaque and be the same colour as the cell:
  // invisible on normal days, but it is what LVGL's own calendar draw callback
  // recolours (and thickens) to mark "today", so it has to exist.
  lv_obj_set_style_border_color(cal, cell_bg, LV_PART_ITEMS);
  lv_obj_set_style_border_opa(cal, LV_OPA_COVER, LV_PART_ITEMS);
  // Days that carry events are marked CHECKED and get the scheme accent as an
  // outline. "Today" is drawn separately by LVGL's own calendar draw callback
  // (it tags that button LV_CALENDAR_CTRL_TODAY and pulls
  // lv_theme_get_color_primary()), which apply_theme_accent() drives. A
  // LV_STATE_USER_1 style would never fire: lv_buttonmatrix only maps
  // CHECKED/DISABLED/PRESSED/... to states, never CUSTOM_1..4 -> USER_1..4.
  // Setting these on the object (rather than through a local lv_style_t) is what
  // makes a scheme change a plain re-call of this function.
  lv_obj_set_style_radius(cal, 7, LV_PART_ITEMS | LV_STATE_CHECKED);
  lv_obj_set_style_border_width(cal, 2, LV_PART_ITEMS | LV_STATE_CHECKED);
  lv_obj_set_style_border_opa(cal, LV_OPA_COVER, LV_PART_ITEMS | LV_STATE_CHECKED);
  lv_obj_set_style_border_color(cal, scheme_accent(), LV_PART_ITEMS | LV_STATE_CHECKED);
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
  // Apply to labels and calendar
  if (month_label) lv_obj_set_style_text_color(month_label, text_color, 0);
  if (date_time_label) lv_obj_set_style_text_color(date_time_label, text_color, 0);
  if (holiday_label) lv_obj_set_style_text_color(holiday_label, text_color, 0);
  if (build_version_label) lv_obj_set_style_text_color(build_version_label, text_color, 0);
  apply_theme_accent(); // keep the theme's dark flag in step with the slider
  apply_calendar_theme(calendar);
  // Redraw weather and events to apply changes
  updateWeatherDisplay();
  updateEventDisplay(calendar);
  // Save to preferences
  preferences.begin("ui", false);
  preferences.putInt("ui_darkness", g_ui_darkness);
  preferences.end();
}
// Re-initialise the LVGL theme with the active scheme's accent. Cheap and safe
// to call repeatedly: lv_theme_default_init() allocates its theme object once
// (lv_theme_default_is_inited()) and returns early when nothing changed.
static void apply_theme_accent() {
  lv_palette_t p = scheme_palette();
  lv_palette_t secondary = (lv_palette_t)((p + 3) % LV_PALETTE_LAST); // same pairing the demo uses
  lv_theme_default_init(lv_display_get_default(), lv_palette_main(p),
                        lv_palette_main(secondary), g_ui_darkness > 50,
                        &lv_font_montserrat_14);
}
// Push the active scheme through every object this app colours explicitly.
// Mirrors the refresh darkness_slider_cb() already does for the light/dark
// switch. Both displays rebuild their widgets, so both need a re-render.
static void apply_color_scheme() {
  apply_theme_accent();
  if (prev_btn_obj) {
    lv_obj_set_style_bg_color(prev_btn_obj, scheme_accent(), 0);
    lv_obj_set_style_bg_color(prev_btn_obj, scheme_accent_dark(), LV_STATE_PRESSED);
  }
  if (next_btn_obj) {
    lv_obj_set_style_bg_color(next_btn_obj, scheme_accent(), 0);
    lv_obj_set_style_bg_color(next_btn_obj, scheme_accent_dark(), LV_STATE_PRESSED);
  }
  if (color_btn) {
    lv_obj_set_style_bg_color(color_btn, scheme_accent(), 0);
    lv_obj_set_style_bg_color(color_btn, scheme_accent_dark(), LV_STATE_PRESSED);
  }
  apply_calendar_theme(calendar);
  updateEventDisplay(calendar);   // event cards bake their colours in at creation
  updateWeatherDisplay();         // ...as do the three weather chips
}

// ---- Floating colour-scheme selector (ported from LVGL's Widgets demo) -----
// A round accent-coloured button in the bottom-right corner. Tapping it animates
// a pill-shaped strip of swatches out to the left; tapping a swatch applies that
// scheme and collapses the strip again. The strip is FLOATING so it overlays the
// weather card while open - an 800x480 screen this full has no genuinely free
// corner left.
static void color_changer_anim_cb(void *var, int32_t v) {
  lv_obj_t *obj = (lv_obj_t *)var;
  if (!obj) return;
  lv_obj_t *par = lv_obj_get_parent(obj);
  if (!par) return;
  int32_t max_w = lv_obj_get_width(par) - 24;
  lv_obj_set_width(obj, lv_map(v, 0, 256, SCHEME_BTN_SIZE, max_w));
  lv_obj_align(obj, LV_ALIGN_BOTTOM_RIGHT, -12, -12);
  if (v > LV_OPA_COVER) v = LV_OPA_COVER;
  for (uint32_t i = 0; i < lv_obj_get_child_count(obj); i++) {
    lv_obj_set_style_opa(lv_obj_get_child(obj, i), (lv_opa_t)v, 0);
  }
}
static bool color_strip_is_collapsed() {
  if (!color_cont) return true;
  return lv_obj_get_width(color_cont) < lv_disp_get_hor_res(lv_disp_get_default()) / 2;
}
static void color_changer_toggle() {
  if (!color_cont) return;
  bool collapsed = color_strip_is_collapsed();
  lv_anim_t a;
  lv_anim_init(&a);
  lv_anim_set_var(&a, color_cont);
  lv_anim_set_exec_cb(&a, color_changer_anim_cb);
  lv_anim_set_duration(&a, 200);
  lv_anim_set_values(&a, collapsed ? 0 : 256, collapsed ? 256 : 0);
  lv_anim_start(&a);
}
static void color_changer_event_cb(lv_event_t *e) {
  if (lv_event_get_code(e) == LV_EVENT_CLICKED) color_changer_toggle();
}
static void color_event_cb(lv_event_t *e) {
  if (lv_event_get_code(e) != LV_EVENT_CLICKED) return;
  // The swatches are only opacity-faded, so they still receive clicks while the
  // strip is collapsed. Ignore those, otherwise a stray tap near the round
  // button would silently change the scheme.
  if (color_strip_is_collapsed()) return;
  intptr_t idx = (intptr_t)lv_event_get_user_data(e);
  if (idx < 0 || idx >= COLOR_SCHEME_COUNT) return;
  g_ui_scheme = (int)idx;
  apply_color_scheme();
  preferences.begin("ui", false);
  preferences.putInt("ui_scheme", g_ui_scheme);
  preferences.end();
  color_changer_toggle(); // collapse again once a choice is made
}
static void create_color_changer() {
  if (color_cont) return;
  color_cont = lv_obj_create(lv_scr_act());
  lv_obj_remove_style_all(color_cont);
  lv_obj_set_flex_flow(color_cont, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(color_cont, LV_FLEX_ALIGN_SPACE_EVENLY, LV_FLEX_ALIGN_CENTER,
                        LV_FLEX_ALIGN_CENTER);
  lv_obj_add_flag(color_cont, LV_OBJ_FLAG_FLOATING);
  lv_obj_clear_flag(color_cont, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_style_bg_color(color_cont, lv_color_white(), 0);
  lv_obj_set_style_bg_opa(color_cont, LV_OPA_COVER, 0);
  lv_obj_set_style_radius(color_cont, LV_RADIUS_CIRCLE, 0);
  lv_obj_set_style_pad_left(color_cont, 10, 0);
  // Right padding the width of the button, so the collapsed strip is exactly a
  // circle hidden underneath it.
  lv_obj_set_style_pad_right(color_cont, SCHEME_BTN_SIZE, 0);
  lv_obj_set_style_pad_column(color_cont, 4, 0);
  lv_obj_set_size(color_cont, SCHEME_BTN_SIZE, SCHEME_BTN_SIZE);
  lv_obj_align(color_cont, LV_ALIGN_BOTTOM_RIGHT, -12, -12);
  for (int i = 0; i < COLOR_SCHEME_COUNT; i++) {
    lv_obj_t *c = lv_button_create(color_cont);
    lv_obj_set_style_bg_color(c, lv_palette_main(color_schemes[i].palette), 0);
    lv_obj_set_style_radius(c, LV_RADIUS_CIRCLE, 0);
    lv_obj_set_style_shadow_width(c, 0, 0);
    lv_obj_set_style_opa(c, LV_OPA_TRANSP, 0); // faded in by color_changer_anim_cb
    lv_obj_set_size(c, SCHEME_SWATCH_SIZE, SCHEME_SWATCH_SIZE);
    lv_obj_clear_flag(c, LV_OBJ_FLAG_SCROLL_ON_FOCUS);
    lv_obj_add_event_cb(c, color_event_cb, LV_EVENT_CLICKED, (void *)(intptr_t)i);
  }
  // Created after the strip, so it paints on top of the collapsed swatches.
  color_btn = lv_button_create(lv_scr_act());
  // NOTE: LV_OBJ_FLAG_A | LV_OBJ_FLAG_B is fine in the demo's C source but a
  // hard error in C++, because lv_obj_add_flag() takes a single lv_obj_flag_t.
  lv_obj_add_flag(color_btn, LV_OBJ_FLAG_FLOATING);
  lv_obj_add_flag(color_btn, LV_OBJ_FLAG_CLICKABLE);
  lv_obj_set_style_bg_color(color_btn, scheme_accent(), 0);
  lv_obj_set_style_bg_color(color_btn, scheme_accent_dark(), LV_STATE_PRESSED);
  lv_obj_set_style_radius(color_btn, LV_RADIUS_CIRCLE, 0);
  lv_obj_set_style_shadow_color(color_btn, lv_color_hex(0x000000), 0);
  lv_obj_set_style_shadow_width(color_btn, 10, 0);
  lv_obj_set_style_shadow_opa(color_btn, LV_OPA_30, 0);
  lv_obj_set_size(color_btn, SCHEME_BTN_SIZE, SCHEME_BTN_SIZE);
  lv_obj_align(color_btn, LV_ALIGN_BOTTOM_RIGHT, -12, -12);
  lv_obj_add_event_cb(color_btn, color_changer_event_cb, LV_EVENT_CLICKED, NULL);
  // The demo paints LV_SYMBOL_TINT as a bg_image; a centred label is the same
  // glyph without depending on bg-image tiling.
  lv_obj_t *icon = lv_label_create(color_btn);
  lv_label_set_text(icon, LV_SYMBOL_TINT);
  lv_obj_set_style_text_font(icon, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(icon, lv_color_white(), 0);
  lv_obj_center(icon);
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
  // setup_display() created the display (and with it LVGL's default blue theme),
  // so push the stored scheme's accent into the theme now.
  apply_theme_accent();
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
  // ---- Calendar card styling -------------------------------------------
  lv_obj_set_style_radius(calendar, 12, LV_PART_MAIN);
  lv_obj_set_style_border_width(calendar, 2, LV_PART_MAIN);
  lv_obj_set_style_pad_all(calendar, 6, LV_PART_MAIN);
  lv_obj_set_style_shadow_color(calendar, lv_color_hex(0x000000), LV_PART_MAIN);
  lv_obj_set_style_shadow_width(calendar, 16, LV_PART_MAIN);
  lv_obj_set_style_shadow_opa(calendar, LV_OPA_20, LV_PART_MAIN);
  lv_obj_set_style_shadow_offset_y(calendar, 4, LV_PART_MAIN);
  // Rounded day cells with a little breathing room between them. The item
  // border must stay >= 1px: lv_draw_rect drops the border draw descriptor when
  // the width is 0, and LVGL's calendar marks "today" by recolouring it.
  lv_obj_set_style_radius(calendar, 7, LV_PART_ITEMS);
  lv_obj_set_style_border_width(calendar, 1, LV_PART_ITEMS);
  lv_obj_t *cal_btnm = lv_calendar_get_btnmatrix(calendar);
  if (cal_btnm) {
    lv_obj_set_style_pad_row(cal_btnm, 4, LV_PART_MAIN);
    lv_obj_set_style_pad_column(cal_btnm, 4, LV_PART_MAIN);
    lv_obj_set_style_pad_all(cal_btnm, 0, LV_PART_MAIN);
  }
  // Everything accent-coloured about the calendar (event-day outline, pressed
  // cells, and the "today" marker LVGL draws from the theme primary) is applied
  // here, so a colour-scheme change is just another call to it.
  apply_calendar_theme(calendar);
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
  // Render the panels with whatever data we already have. NO network traffic
  // happens while the screen is being built - setup() fetches everything once
  // the UI is up, with the bank holidays fetched last of all.
  updateEventDisplay(calendar);
  updateWeatherDisplay();
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
  prev_btn_obj = prev_btn;
  lv_obj_set_style_bg_color(prev_btn, scheme_accent(), 0);
  lv_obj_set_style_bg_color(prev_btn, scheme_accent_dark(), LV_STATE_PRESSED);
  lv_obj_set_style_radius(prev_btn, 10, 0);
  lv_obj_set_style_shadow_color(prev_btn, lv_color_hex(0x000000), 0);
  lv_obj_set_style_shadow_width(prev_btn, 8, 0);
  lv_obj_set_style_shadow_opa(prev_btn, LV_OPA_20, 0);
  lv_obj_t *prev_label = lv_label_create(prev_btn);
  lv_label_set_text(prev_label, LV_SYMBOL_LEFT);
  lv_obj_center(prev_label);
  lv_obj_set_style_text_font(prev_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(prev_label, lv_color_hex(0xFFFFFF), 0);
  lv_obj_t *settings_btn_obj = lv_button_create(button_bar);
  lv_obj_add_event_cb(settings_btn_obj, settings_btn_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_set_style_bg_color(settings_btn_obj, lv_color_hex(0x2F3640), 0);
  lv_obj_set_style_bg_color(settings_btn_obj, lv_color_hex(0x1E242B), LV_STATE_PRESSED);
  lv_obj_set_style_radius(settings_btn_obj, 10, 0);
  lv_obj_set_style_shadow_color(settings_btn_obj, lv_color_hex(0x000000), 0);
  lv_obj_set_style_shadow_width(settings_btn_obj, 8, 0);
  lv_obj_set_style_shadow_opa(settings_btn_obj, LV_OPA_20, 0);
  lv_obj_t *settings_label = lv_label_create(settings_btn_obj);
  lv_label_set_text(settings_label, LV_SYMBOL_SETTINGS);
  lv_obj_center(settings_label);
  lv_obj_set_style_text_font(settings_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(settings_label, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_size(settings_btn_obj, 40, 40);
  lv_obj_t *next_btn = lv_button_create(button_bar);
  lv_obj_add_event_cb(next_btn, next_month_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_set_size(next_btn, 40, 40);
  next_btn_obj = next_btn;
  lv_obj_set_style_bg_color(next_btn, scheme_accent(), 0);
  lv_obj_set_style_bg_color(next_btn, scheme_accent_dark(), LV_STATE_PRESSED);
  lv_obj_set_style_radius(next_btn, 10, 0);
  lv_obj_set_style_shadow_color(next_btn, lv_color_hex(0x000000), 0);
  lv_obj_set_style_shadow_width(next_btn, 8, 0);
  lv_obj_set_style_shadow_opa(next_btn, LV_OPA_20, 0);
  lv_obj_t *next_label = lv_label_create(next_btn);
  lv_label_set_text(next_label, LV_SYMBOL_RIGHT);
  lv_obj_center(next_label);
  lv_obj_set_style_text_font(next_label, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(next_label, lv_color_hex(0xFFFFFF), 0);
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
  create_color_changer();
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
// ---- Day preview ----------------------------------------------------------
// Shown when a calendar day that already has events is tapped: a compact list of
// that day's events (each one opens the full details popup) plus an
// "Add New Event" button.
void close_day_events_popup() {
  if (day_events_popup) {
    lv_obj_del(day_events_popup);
    day_events_popup = nullptr;
  }
}
// Indices of events overlapping the given day. Uses the parsed time_t range so a
// multi-day event shows up on every day it spans.
static int collect_events_for_date(const lv_calendar_date_t *date, int *out, int max_out) {
  if (!date || !out || max_out <= 0) return 0;
  struct tm day_tm = {0};
  day_tm.tm_year = date->year - 1900;
  day_tm.tm_mon  = date->month - 1;
  day_tm.tm_mday = date->day;
  day_tm.tm_isdst = -1;
  time_t day_start = mktime(&day_tm);
  if (day_start == (time_t)-1) return 0;
  time_t day_end = day_start + 86400;
  int n = 0;
  for (int i = 0; i < numEvents && n < max_out; i++) {
    time_t s = events[i].start_time;
    time_t e = events[i].end_time;
    if (e < s) e = s; // guard against an unparsed or reversed range
    if (s < day_end && e >= day_start) out[n++] = i;
  }
  return n;
}
static void day_event_item_cb(lv_event_t *e) {
  lv_obj_t *chip = (lv_obj_t *)lv_event_get_current_target(e);
  int index = (int)(intptr_t)lv_obj_get_user_data(chip);
  if (index >= 0 && index < numEvents) {
    show_event_details(index, false);
  }
}
static void day_events_add_cb(lv_event_t *e) {
  lv_calendar_date_t d = day_preview_date;
  close_day_events_popup();
  show_new_event_popup(&d);
}
static void day_events_close_cb(lv_event_t *e) {
  close_day_events_popup();
}
void show_day_events_popup(lv_calendar_date_t *date, const int *indices, int count) {
  if (!date) return;
  if (!indices || count <= 0) { // Nothing to preview: go straight to add-new
    show_new_event_popup(date);
    return;
  }
  close_day_events_popup();
  day_preview_date = *date;

  day_events_popup = lv_obj_create(lv_scr_act());
  lv_obj_set_size(day_events_popup, 700, 420);
  lv_obj_align(day_events_popup, LV_ALIGN_CENTER, 0, 0);
  lv_obj_set_style_bg_color(day_events_popup, lv_color_hex(0x000000), 0);
  lv_obj_set_style_border_color(day_events_popup, lv_color_hex(0xFFFFFF), 0);
  lv_obj_set_style_border_width(day_events_popup, 2, 0);
  lv_obj_set_style_radius(day_events_popup, 10, 0);
  lv_obj_set_style_pad_all(day_events_popup, 10, 0);
  lv_obj_set_style_pad_row(day_events_popup, 8, 0);
  lv_obj_set_scroll_dir(day_events_popup, LV_DIR_VER);
  lv_obj_set_scrollbar_mode(day_events_popup, LV_SCROLLBAR_MODE_AUTO);
  lv_obj_set_flex_flow(day_events_popup, LV_FLEX_FLOW_COLUMN);

  struct tm header_tm = {0};
  header_tm.tm_year = date->year - 1900;
  header_tm.tm_mon = date->month - 1;
  header_tm.tm_mday = date->day;
  char header_buf[64];
  strftime(header_buf, sizeof(header_buf), "%A %d %B %Y", &header_tm);
  lv_obj_t *title = lv_label_create(day_events_popup);
  lv_label_set_text(title, header_buf);
  lv_obj_set_style_text_font(title, &lv_font_montserrat_24, 0);
  lv_obj_set_style_text_color(title, lv_color_hex(0xFFFFFF), 0);
  lv_obj_t *subtitle = lv_label_create(day_events_popup);
  lv_label_set_text(subtitle, (String(count) + (count == 1 ? " event" : " events")).c_str());
  lv_obj_set_style_text_font(subtitle, &lv_font_montserrat_14, 0);
  lv_obj_set_style_text_color(subtitle, lv_color_hex(0x9FB3C8), 0);

  for (int k = 0; k < count; k++) {
    int i = indices[k];
    if (i < 0 || i >= numEvents) continue;
    lv_obj_t *chip = lv_obj_create(day_events_popup);
    lv_obj_set_width(chip, LV_PCT(100));
    lv_obj_set_height(chip, LV_SIZE_CONTENT);
    lv_obj_set_style_bg_color(chip, scheme_accent_dark(), 0);
    lv_obj_set_style_bg_grad_color(chip, scheme_accent_deep(), 0);
    lv_obj_set_style_bg_grad_dir(chip, LV_GRAD_DIR_HOR, 0);
    lv_obj_set_style_radius(chip, 8, 0);
    lv_obj_set_style_border_width(chip, 0, 0);
    lv_obj_set_style_pad_all(chip, 8, 0);
    lv_obj_set_style_pad_row(chip, 2, 0);
    lv_obj_set_flex_flow(chip, LV_FLEX_FLOW_COLUMN);
    lv_obj_clear_flag(chip, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_set_scrollbar_mode(chip, LV_SCROLLBAR_MODE_OFF);
    lv_obj_set_user_data(chip, (void*)(intptr_t)i);
    lv_obj_add_event_cb(chip, day_event_item_cb, LV_EVENT_PRESSED, NULL);

    lv_obj_t *summary = lv_label_create(chip);
    lv_label_set_text(summary, events[i].summary.isEmpty() ? "(no title)" : events[i].summary.c_str());
    lv_obj_set_style_text_font(summary, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(summary, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_width(summary, LV_PCT(100));
    lv_label_set_long_mode(summary, LV_LABEL_LONG_WRAP);

    String when = events[i].isAllDay
                    ? String("All day")
                    : events[i].start.substring(11, 16) + " - " + events[i].end.substring(11, 16);
    lv_obj_t *when_lbl = lv_label_create(chip);
    lv_label_set_text(when_lbl, when.c_str());
    lv_obj_set_style_text_font(when_lbl, &lv_font_montserrat_14, 0);
    lv_obj_set_style_text_color(when_lbl, lv_color_hex(0xFFD166), 0);
  }

  lv_obj_t *btn_row = lv_obj_create(day_events_popup);
  lv_obj_set_width(btn_row, LV_PCT(100));
  lv_obj_set_height(btn_row, LV_SIZE_CONTENT);
  lv_obj_set_style_bg_opa(btn_row, LV_OPA_TRANSP, 0);
  lv_obj_set_style_border_width(btn_row, 0, 0);
  lv_obj_set_style_pad_all(btn_row, 0, 0);
  lv_obj_set_style_pad_column(btn_row, 10, 0);
  lv_obj_set_flex_flow(btn_row, LV_FLEX_FLOW_ROW);
  lv_obj_set_flex_align(btn_row, LV_FLEX_ALIGN_END, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);
  lv_obj_clear_flag(btn_row, LV_OBJ_FLAG_SCROLLABLE);
  lv_obj_set_scrollbar_mode(btn_row, LV_SCROLLBAR_MODE_OFF);

  lv_obj_t *add_btn = lv_button_create(btn_row);
  lv_obj_set_size(add_btn, 180, 42);
  lv_obj_set_style_bg_color(add_btn, lv_color_hex(0x00A86B), 0);
  lv_obj_set_style_radius(add_btn, 10, 0);
  lv_obj_add_event_cb(add_btn, day_events_add_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *add_lbl = lv_label_create(add_btn);
  lv_label_set_text(add_lbl, LV_SYMBOL_PLUS " Add New Event");
  lv_obj_center(add_lbl);
  lv_obj_set_style_text_font(add_lbl, &lv_font_montserrat_14, 0);

  lv_obj_t *close_btn = lv_button_create(btn_row);
  lv_obj_set_size(close_btn, 120, 42);
  lv_obj_set_style_bg_color(close_btn, lv_color_hex(0xFF0000), 0);
  lv_obj_set_style_radius(close_btn, 10, 0);
  lv_obj_add_event_cb(close_btn, day_events_close_cb, LV_EVENT_PRESSED, NULL);
  lv_obj_t *close_lbl = lv_label_create(close_btn);
  lv_label_set_text(close_lbl, "Close");
  lv_obj_center(close_lbl);
  lv_obj_set_style_text_font(close_lbl, &lv_font_montserrat_14, 0);
}
void calendar_event_cb(lv_event_t * e) {
  unsigned long currentTime = millis();
  if (currentTime - lastEventTime < debounceDelay) {
    if (debug == 1) Serial.println("[APP] Event debounced");
    return;
  }
  lastEventTime = currentTime;
  // The popups are meant to be modal but do not cover the whole calendar, so a
  // tap can still land on the grid. Ignore it while a window is open; otherwise
  // a second popup is created and the first one is orphaned (its buttons then
  // act on a stale/NULL global and dereference it).
  if (new_event_popup || event_details_popup || day_events_popup || settings_popup || update_popup) {
    if (debug == 1) Serial.println("[APP] Calendar tap ignored: a popup is already open");
    return;
  }
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
  // A day that already has events shows a preview first; an empty day goes
  // straight to the add-event form.
  int day_indices[64];
  int day_count = collect_events_for_date(&date, day_indices, 64);
  if (debug == 1) Serial.println("[APP] Day has " + String(day_count) + " event(s)");
  if (day_count > 0) {
    show_day_events_popup(&date, day_indices, day_count);
  } else {
    show_new_event_popup(&date);
  }
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
  // EVERYTHING that follows depends on a correct clock: the calendar month,
  // event dates, reminders and the bank-holiday lookup. So retry until NTP
  // hands us a sane year instead of giving up on the first attempt.
  struct tm timeinfo;
  bool synced = false;
  for (int attempt = 1; attempt <= 5 && !synced; attempt++) {
    configTime(0, 0, ntpServer); // sync in UTC first
    if (getLocalTime(&timeinfo) && (timeinfo.tm_year + 1900) >= 2024) {
      synced = true;
    } else {
      if (debug == 1) Serial.printf("[APP] NTP not ready (attempt %d/5)\n", attempt);
      delay(500);
    }
  }
  if (!synced) {
    Serial.println("[APP] WARNING: NTP time not available - dates may be wrong");
  }
  // Now set the UK timezone string for automatic GMT/BST handling
  setenv("TZ", "GMT0BST,M3.5.0/1,M10.5.0", 1);
  tzset();
  if (synced && getLocalTime(&timeinfo) && debug == 1) {
    Serial.println("[APP] Time synced: " + String(timeinfo.tm_year + 1900) + "-" +
                   String(timeinfo.tm_mon + 1) + "-" + String(timeinfo.tm_mday) + " " +
                   String(timeinfo.tm_hour) + ":" + String(timeinfo.tm_min));
  }
}
// Modified setup() function: Add the initTime() call after successful WiFi connection
// ---------------------------------------------------------------------------
// Everything fetched once the UI is already on screen.
//
// These are eight SEPARATE blocking HTTPS requests, each paying for its own TLS
// handshake, so running them back to back froze the whole UI for the entire
// sequence. They are now a queue: loop() runs exactly ONE step per iteration and
// LVGL draws in between, so the calendar is usable immediately and each panel
// fills in as its data lands. Total time is about the same, but the device never
// looks wedged and touches are handled throughout.
//
// Bank holidays stay LAST: they are the slowest and least important, so a slow or
// failing gov.uk request can never hold up anything else.
// ---------------------------------------------------------------------------
enum BootFetchStep {
  BOOT_STEP_PARCELBOX = 0,
  BOOT_STEP_WEATHER_LOCATION,
  BOOT_STEP_EVENTS,
  BOOT_STEP_WEATHER,
  BOOT_STEP_FIRMWARE,
  BOOT_STEP_BACKGROUND_NAME,
  BOOT_STEP_BACKGROUND_IMAGE,
  BOOT_STEP_HOLIDAYS, // always last
  BOOT_STEP_COUNT
};
static int bootFetchStep = BOOT_STEP_COUNT; // >= BOOT_STEP_COUNT means idle
static unsigned long lastBootFetchStep = 0;
// Long enough for at least one LVGL refresh (LV_DEF_REFR_PERIOD, 30ms) to land
// between two blocking requests.
#define BOOT_FETCH_GAP_MS 50

static void runBootFetchStep(int step) {
  switch (step) {
    case BOOT_STEP_PARCELBOX:
      fetchParcelBoxCredentials();
      break;
    case BOOT_STEP_WEATHER_LOCATION:
      fetchWeatherLocation();
      break;
    case BOOT_STEP_EVENTS:
      fetchEvents();
      updateEventDisplay(calendar);
      break;
    case BOOT_STEP_WEATHER:
      fetchWeather();
      updateWeatherDisplay();
      break;
    case BOOT_STEP_FIRMWARE:
      checkFirmwareUpdate();
      updateFirmwareButton();
      break;
    case BOOT_STEP_BACKGROUND_NAME:
      fetchBackgroundFilename();
      break;
    case BOOT_STEP_BACKGROUND_IMAGE:
      fetchAndSetBackgroundImage();
      break;
    case BOOT_STEP_HOLIDAYS:
      fetchBankHolidays();
      updateHolidayLabel();
      lastHolidayUpdate = millis();
      break;
    default:
      break;
  }
}
// Called from loop(): advances the queue by at most one step per invocation.
static void serviceBootFetchQueue() {
  if (bootFetchStep >= BOOT_STEP_COUNT) return;
  if (is_ota_updating) return;
  if (millis() - lastBootFetchStep < BOOT_FETCH_GAP_MS) return;
  // Never start a request that blocks for a second or more while a finger is
  // down: the tap would be swallowed and the screen would look dead. Wait for
  // release, then re-check after another gap.
  lv_indev_t *indev = lv_indev_get_next(NULL);
  if (indev && lv_indev_get_state(indev) == LV_INDEV_STATE_PRESSED) {
    lastBootFetchStep = millis();
    return;
  }
  runBootFetchStep(bootFetchStep);
  bootFetchStep++;
  lastBootFetchStep = millis();
}
// Kept as one call so the setup wizards (WiFi / API code / location) behave the
// same as a normal boot - they just (re)start the queue now instead of blocking.
void fetchAfterUiReady() {
  bootFetchStep = BOOT_STEP_PARCELBOX;
  lastBootFetchStep = 0;
}

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
  g_ui_scheme = preferences.getInt("ui_scheme", 0);
  preferences.end();
  if (g_ui_scheme < 0 || g_ui_scheme >= COLOR_SCHEME_COUNT) g_ui_scheme = 0;
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
          // ---- 1) Build the whole UI first ------------------------------
          // initTime() above has already synced the clock, so the calendar
          // month, event dates and date/time labels are correct from the first
          // paint. setup_calendar() itself does no network I/O.
          setup_calendar();

          // ---- 2) Screen is up: fetch everything, holidays last --------
          fetchAfterUiReady();
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
  // LVGL runs at all times now, including during a firmware update, so the update
  // screen can show a live progress bar instead of a frozen frame.
  // loop_display() is the ONLY caller of lv_task_handler(): the OTA code used to
  // call it re-entrantly from inside a button callback, which is what made the
  // display jump.
  loop_display();
  lv_tick_inc(5);

  if (is_ota_updating) {
    serviceOta(); // one bounded slice of the download, then hand back to LVGL
    delay(1);
    return;
  }

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
  // Post-UI fetch queue: one blocking request per iteration, with LVGL drawing
  // in between, instead of the whole sequence in one go.
  serviceBootFetchQueue();
  // WiFi wizard: finishes the async scan and the in-progress connection attempt.
  serviceWifiSetup();

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
  if (numEvents >= MAX_EVENTS) {
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
// ---- Background image: flash cache -----------------------------------------
// The server serves a 1,152,000-byte RGB888 blob (800*480*3) over HTTPS, and it
// only ever changes when backgroundFilename does. The last one is therefore kept
// in the LittleFS partition - that is the "spiffs" partition from default_8MB.csv
// at 0x670000, 1.5MB, which nothing else in this project uses. Later boots (and
// the hourly refresh) read it from flash instead of re-downloading it, skipping a
// TLS handshake AND a 1.1MB transfer every time.
#define BG_CACHE_PATH "/bg.rgb"
#define BG_EXPECTED_SIZE (800 * 480 * 3)
static bool bgFsReady = false;
// lv_img_set_src() keeps the pointer it is handed, so this descriptor must
// outlive the call. It used to be a local, which left LVGL reading a stale stack
// frame as soon as the function returned.
static lv_image_dsc_t bg_dsc;
// The buffer LVGL is currently drawing from, so it can be released when a new
// background replaces it (the old code leaked one 1.1MB buffer per change).
static uint8_t *bg_buffer = nullptr;

static bool bgCacheMount() {
  if (bgFsReady) return true;
  bgFsReady = LittleFS.begin(true); // format the partition on first ever use
  if (!bgFsReady && debug == 1) {
    Serial.println("[APP] LittleFS mount failed - background image will not be cached");
  }
  return bgFsReady;
}
static void bgApply(uint8_t *buf, size_t len) {
  if (bg_img) {
    lv_obj_del(bg_img);
    bg_img = nullptr;
  }
  // Safe to release the old buffer only now: the object that referenced it is
  // gone and LVGL is not mid-render here (this runs from loop()).
  if (bg_buffer && bg_buffer != buf) free(bg_buffer);
  bg_buffer = buf;
  memset(&bg_dsc, 0, sizeof(bg_dsc));
  bg_dsc.header.cf = LV_COLOR_FORMAT_RGB888;
  bg_dsc.header.w = 800;
  bg_dsc.header.h = 480;
  bg_dsc.data_size = len;
  bg_dsc.data = buf;
  bg_img = lv_img_create(lv_scr_act());
  lv_img_set_src(bg_img, &bg_dsc);
  lv_obj_set_size(bg_img, LV_PCT(100), LV_PCT(100));
  lv_obj_align(bg_img, LV_ALIGN_CENTER, 0, 0);
  lv_obj_move_background(bg_img);
  lv_obj_invalidate(lv_scr_act());
}
// Load the cached copy, but only when it is for exactly the file we want now.
static bool bgLoadFromCache() {
  if (!bgCacheMount()) return false;
  preferences.begin("ui", false);
  String cachedName = preferences.getString("bg_name", "");
  preferences.end();
  if (cachedName.isEmpty() || cachedName != backgroundFilename) return false;
  File f = LittleFS.open(BG_CACHE_PATH, "r");
  if (!f) return false;
  if ((int)f.size() != BG_EXPECTED_SIZE) {
    f.close();
    if (debug == 1) Serial.println("[APP] Cached background is the wrong size, re-downloading");
    return false;
  }
  uint8_t *buf = (uint8_t *)ps_malloc(BG_EXPECTED_SIZE);
  if (!buf) {
    f.close();
    return false;
  }
  size_t got = f.read(buf, BG_EXPECTED_SIZE);
  f.close();
  if (got != (size_t)BG_EXPECTED_SIZE) {
    free(buf);
    if (debug == 1) Serial.println("[APP] Cached background read short, re-downloading");
    return false;
  }
  if (debug == 1) Serial.println("[APP] Background restored from flash cache (no download)");
  bgApply(buf, BG_EXPECTED_SIZE);
  return true;
}
static void bgSaveToCache(const uint8_t *buf, size_t len) {
  if (!bgCacheMount()) return;
  File f = LittleFS.open(BG_CACHE_PATH, "w");
  if (!f) {
    if (debug == 1) Serial.println("[APP] Could not open the background cache for writing");
    return;
  }
  size_t wrote = f.write(buf, len);
  f.close();
  if (wrote != len) {
    if (debug == 1) Serial.println("[APP] Background cache write incomplete, will re-download next boot");
    return;
  }
  preferences.begin("ui", false);
  preferences.putString("bg_name", backgroundFilename);
  preferences.end();
  if (debug == 1) Serial.println("[APP] Background cached to flash");
}
void fetchAndSetBackgroundImage() {
  if (backgroundFilename.isEmpty()) {
    if (debug == 1) Serial.println("[APP] No background filename, skipping image fetch");
    return;
  }
  // Fast path: same file as last time and it is already on flash.
  if (bgLoadFromCache()) return;

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
  if (contentLength != BG_EXPECTED_SIZE) {
    if (debug == 1) Serial.println("[APP] Invalid background size: " + String(contentLength) + " bytes (expected " + String(BG_EXPECTED_SIZE) + ")");
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
    } else {
      // Nothing buffered: yield so the WiFi stack, the idle task and LVGL can
      // run. The old code delayed 1ms on EVERY pass, which threw away time while
      // data was flowing; a bare spin would instead starve the WiFi task.
      delay(1);
    }
  }
  if (totalRead != contentLength) {
    if (debug == 1) Serial.println("[APP] Incomplete background image download: " + String(totalRead) + "/" + String(contentLength) + " bytes");
    free(imageBuffer);
    http.end();
    return;
  }
  if (debug == 1) Serial.println("[APP] Download complete: " + String(totalRead) + " bytes");
  // Cache before applying, so a rendering problem cannot lose the download.
  bgSaveToCache(imageBuffer, contentLength);
  bgApply(imageBuffer, contentLength);
  if (debug == 1) Serial.println("[APP] Background image set successfully via LVGL");
  http.end();
}