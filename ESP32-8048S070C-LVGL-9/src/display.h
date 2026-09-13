#ifndef DISPLAY_H
#define DISPLAY_H
#include <lvgl.h> /* 9.2.2 */
#include <Arduino_GFX_Library.h> /* 1.5.0 */
#include <TAMC_GT911.h> /* 1.0.2 */
#include <esp_heap_caps.h> // For PSRAM allocation
// Configuration for Display and Touch
#define TFT_BL 2
// Backlight dimming. GPIO 2 drives the panel's backlight and is otherwise unused
// on this board, so it can carry PWM. 5 kHz keeps it above the audible range (a
// slower PWM makes the backlight converter whine) and 10 bits gives far finer
// steps than the 0-100 the UI exposes. Channel 0 is free: nothing else in this
// firmware uses LEDC.
#define TFT_BL_LEDC_CHANNEL 0
#define TFT_BL_PWM_FREQ 5000
#define TFT_BL_PWM_BITS 10
#define TFT_BL_PWM_MAX ((1 << TFT_BL_PWM_BITS) - 1)
#define TOUCH_GT911_SCL 20
#define TOUCH_GT911_SDA 19
#define TOUCH_GT911_INT -1
#define TOUCH_GT911_RST 38
#define TOUCH_MAP_X1 800
#define TOUCH_MAP_X2 0
#define TOUCH_MAP_Y1 480
#define TOUCH_MAP_Y2 0
// Initialize touchscreen object
TAMC_GT911 ts(TOUCH_GT911_SDA, TOUCH_GT911_SCL, TOUCH_GT911_INT, TOUCH_GT911_RST, max(TOUCH_MAP_X1, TOUCH_MAP_X2), max(TOUCH_MAP_Y1, TOUCH_MAP_Y2));
// RGB Panel configuration
Arduino_ESP32RGBPanel rgbpanel(
    41 /* DE */, 40 /* VSYNC */, 39 /* HSYNC */, 42 /* PCLK */,
    14 /* R0 */, 21 /* R1 */, 47 /* R2 */, 48 /* R3 */, 45 /* R4 */,
    9 /* G0 */, 46 /* G1 */, 3 /* G2 */, 8 /* G3 */, 16 /* G4 */, 1 /* G5 */,
    15 /* B0 */, 7 /* B1 */, 6 /* B2 */, 5 /* B3 */, 4 /* B4 */,
    0 /* hsync_polarity */, 20 /* hsync_front_porch */, 30 /* hsync_pulse_width */, 16 /* hsync_back_porch */,
    0 /* vsync_polarity */, 22 /* vsync_front_porch */, 13 /* vsync_pulse_width */, 10 /* vsync_back_porch */,
    true /* pclk_active_neg */
);
// The 5th argument is auto_flush, NOT an allocation switch - the RGB panel driver
// always allocates the framebuffer itself (see Arduino_ESP32RGBPanel::getFrameBuffer).
//
// It must be TRUE. The framebuffer lives in PSRAM, and the panel's DMA scans
// physical PSRAM directly while the CPU writes through its cache. With auto_flush
// left false, draw16bitRGBBitmap() skips its Cache_WriteBack_Addr() call and
// nothing else ever flushed either (Arduino_RGB_Display::flush() exists for
// exactly that case, and this project never called it), so freshly drawn pixels
// sat in the cache and the panel kept displaying whatever stale bytes were still
// in PSRAM. That is what produced the frozen pixels and torn lines after a
// redraw: the cache lines only reached PSRAM later, when unrelated writes evicted
// them. Every upstream example passes true here.
Arduino_RGB_Display gfx(800, 480, &rgbpanel, 0, true);
uint32_t screenWidth;
uint32_t screenHeight;
uint32_t bufSize;
lv_display_t *disp;
lv_color_t *disp_draw_buf;
uint32_t millis_cb(void)
{
  return millis();
}
void my_disp_flush(lv_display_t *disp, const lv_area_t *area, uint8_t *px_map)
{
  uint32_t w = lv_area_get_width(area);
  uint32_t h = lv_area_get_height(area);
  gfx.draw16bitRGBBitmap(area->x1, area->y1, (uint16_t *)px_map, w, h);
  lv_disp_flush_ready(disp);
}
void my_touchpad_read(lv_indev_t *indev, lv_indev_data_t *data)
{
  ts.read();
  if (ts.isTouched)
  {
    for (int i = 0; i < ts.touches; i++)
    {
      if (i == 0)
      {
        data->state = LV_INDEV_STATE_PRESSED;
        data->point.x = ts.points[i].x;
        data->point.y = ts.points[i].y;
        // No Serial output here. This runs on every indev poll while a finger is
        // down (tens of times a second), and the printf was not gated by the debug
        // flag, so it added serial latency to every touch and made the UI feel
        // like it had stopped responding.
      }
    }
  }
  else
  {
    data->state = LV_INDEV_STATE_RELEASED;
  }
}
void setup_display()
{
  // Several first-run paths reach this twice: setup() initialises the display so
  // it can show the WiFi wizard, and setup_calendar() calls it again once the
  // wizard finishes. Without this guard the second call allocated a second 384KB
  // LVGL draw buffer, created a second display AND a second input device, and ran
  // gfx.begin()/lv_init() again - leaking the first buffer and leaving LVGL with
  // two displays registered.
  static bool display_ready = false;
  if (display_ready) return;
  display_ready = true;
  Serial.begin(115200);
  Serial.println("Initializing display...");
  Serial.printf("Free heap before init: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));
  Serial.printf("Free PSRAM before init: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  // Initialize PSRAM
  bool psram_available = psramInit();
  Serial.printf("PSRAM initialized: %s\n", psram_available ? "Success" : "Failed");
  // NOTE: no separate framebuffer is allocated here on purpose. The RGB panel
  // owns the framebuffer - Arduino_RGB_Display::begin() below asks the ESP-IDF
  // driver for it via _rgbpanel->getFrameBuffer(). The old code allocated a
  // *second* 800*480*2 (768KB) PSRAM buffer here that nothing ever read or
  // wrote: it just reserved 768KB and pushed the driver's real framebuffer to a
  // different address.
  // Initialize display
  gfx.begin();
  Serial.println("Display initialized");
  Serial.printf("Free heap after display: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));
  Serial.printf("Free PSRAM after display: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  gfx.fillScreen(0xFFFF); // White background
  Serial.println("Screen filled white");
#ifdef TFT_BL
  // PWM instead of a plain on/off output, so the backlight can be dimmed. Starts
  // at full: the saved level is applied by setup() once preferences are readable.
  // Everything else must go through backlight_set() in main.cpp - mixing a
  // digitalWrite() with a pin that LEDC has attached gives undefined duty.
  ledcSetup(TFT_BL_LEDC_CHANNEL, TFT_BL_PWM_FREQ, TFT_BL_PWM_BITS);
  ledcAttachPin(TFT_BL, TFT_BL_LEDC_CHANNEL);
  ledcWrite(TFT_BL_LEDC_CHANNEL, TFT_BL_PWM_MAX);
  Serial.println("Backlight PWM initialised at full");
#endif
  ts.begin();
  ts.setRotation(1);
  Serial.println("Touchscreen initialized");
  lv_init();
  Serial.println("LVGL initialized");
  Serial.printf("Free heap after LVGL: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));
  Serial.printf("Free PSRAM after LVGL: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  lv_tick_set_cb(millis_cb);
  screenWidth = gfx.width();
  screenHeight = gfx.height();
  bufSize = screenWidth * 120; // Increased from 40 to 120 for larger chunks, reducing flushes (adjust based on memory)
  // Allocate LVGL buffers in PSRAM if available, else SRAM
  // For double buffering: allocate for 2 buffers (2 * bufSize pixels * 2 bytes)
  size_t buffer_bytes = 2 * bufSize * sizeof(lv_color_t); // sizeof(lv_color_t) == 2
  disp_draw_buf = (lv_color_t *)(psram_available ? heap_caps_malloc(buffer_bytes, MALLOC_CAP_SPIRAM) : heap_caps_malloc(buffer_bytes, MALLOC_CAP_8BIT));
  if (!disp_draw_buf)
  {
    Serial.println("Failed to allocate LVGL display buffer! Halting...");
    while (true) delay(1000); // Halt execution
  }
  Serial.println("LVGL display buffer allocated");
  Serial.printf("Free heap after buffer: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));
  Serial.printf("Free PSRAM after buffer: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  disp = lv_display_create(screenWidth, screenHeight);
  lv_display_set_flush_cb(disp, my_disp_flush);
  // Use double buffering in partial mode: buf1 and buf2
  lv_color_t *buf2 = disp_draw_buf + bufSize; // Second buffer starts after first
  lv_display_set_buffers(disp, disp_draw_buf, buf2, bufSize, LV_DISPLAY_RENDER_MODE_PARTIAL); // Fixed size to bufSize (pixels per buffer)
  Serial.println("LVGL display created");
  lv_indev_t *indev = lv_indev_create();
  lv_indev_set_type(indev, LV_INDEV_TYPE_POINTER);
  lv_indev_set_read_cb(indev, my_touchpad_read);
  Serial.println("Touch input device created");
  Serial.println("Display setup complete.");
}
void loop_display()
{
  lv_task_handler();
  delay(5);
}
#endif // DISPLAY_H