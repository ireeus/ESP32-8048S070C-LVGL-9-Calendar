#ifndef DISPLAY_H
#define DISPLAY_H
#include <lvgl.h> /* 9.2.2 */
#include <Arduino_GFX_Library.h> /* 1.5.0 */
#include <TAMC_GT911.h> /* 1.0.2 */
#include <esp_heap_caps.h> // For PSRAM allocation
// Configuration for Display and Touch
#define TFT_BL 2
// TFT_BL is an ENABLE pin, not a dimming input. It was briefly driven from a
// 5 kHz LEDC channel to give the backlight a brightness slider, and the panel
// went black at about 60%: 60% of 3.3 V is ~2.0 V, right where a logic input
// stops reading as high. So the pin is level-sensitive, not duty-sensitive -
// anything short of full on reads as off. It must only ever be driven HIGH/LOW.
// Please do not put PWM back on it: dimming has to be done by drawing (a
// translucent overlay, or the existing UI darkness setting), not by this pin.
#define TOUCH_GT911_SCL 20
#define TOUCH_GT911_SDA 19
#define TOUCH_GT911_INT -1
#define TOUCH_GT911_RST 38
#define TOUCH_MAP_X1 800
#define TOUCH_MAP_X2 0
#define TOUCH_MAP_Y1 480
#define TOUCH_MAP_Y2 0
#define TOUCH_PANEL_W max(TOUCH_MAP_X1, TOUCH_MAP_X2)
#define TOUCH_PANEL_H max(TOUCH_MAP_Y1, TOUCH_MAP_Y2)
// Defined in main.cpp. Declared so the touch path can gate its (rare) logging on
// the same flag as the rest of the firmware instead of printing every poll.
extern int debug;
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
// Rolling draw statistics, reported every 5s when debug is on. Two windows are
// kept on purpose, because they answer different questions:
//   flush   = the copy out of the draw buffer into the panel framebuffer
//   handler = the whole lv_task_handler() call, which contains the render AND the
//             flush. If handler time is close to flush time the copy dominates and
//             moving the buffer would not help much; if handler is much larger, the
//             render does, and where the draw buffer lives is what matters.
static uint32_t      flush_stat_us = 0;       // total time spent in the copy
static uint32_t      flush_stat_px = 0;       // pixels copied
static uint32_t      flush_stat_count = 0;    // flushes
static uint32_t      flush_stat_max_us = 0;   // worst single flush
static uint32_t      handler_stat_us = 0;     // total time inside lv_task_handler
static uint32_t      handler_stat_max_us = 0; // worst single call
static uint32_t      handler_stat_count = 0;
static uint32_t      handler_stat_slow = 0;   // calls over a 33ms frame budget
static unsigned long draw_stat_since = 0;     // window start
void my_disp_flush(lv_display_t *disp, const lv_area_t *area, uint8_t *px_map)
{
  uint32_t w = lv_area_get_width(area);
  uint32_t h = lv_area_get_height(area);
  // Every flush is a copy out of the LVGL draw buffer into the panel framebuffer,
  // and because the panel's DMA reads PSRAM directly the driver then has to write
  // that range back out of the CPU cache (auto_flush is true). So the flush is the
  // one place where the panel's real cost shows up.
  const uint32_t t0 = micros();
  gfx.draw16bitRGBBitmap(area->x1, area->y1, (uint16_t *)px_map, w, h);
  const uint32_t spent = micros() - t0;
  flush_stat_us += spent;
  flush_stat_px += w * h;
  flush_stat_count++;
  if (spent > flush_stat_max_us) flush_stat_max_us = spent;
  lv_disp_flush_ready(disp);
}
static void reportDrawStats() {
  if (debug != 1) return;
  const unsigned long now = millis();
  if (draw_stat_since == 0) { draw_stat_since = now; return; }
  if (now - draw_stat_since < 5000UL) return;
  const uint32_t ms = now - draw_stat_since;
  if (!handler_stat_count) { draw_stat_since = now; return; }
  const float kpx_s = (float)flush_stat_px / 1000.0f / ((float)ms / 1000.0f);
  Serial.printf("[DRAW] %lums: handler avg %.2fms max %.2fms (%lu over 33ms) | "
                "flush %lu x avg %.2fms max %.2fms %.0f kpx/s\n",
                (unsigned long)ms,
                (float)handler_stat_us / 1000.0f / (float)handler_stat_count,
                (float)handler_stat_max_us / 1000.0f, (unsigned long)handler_stat_slow,
                (unsigned long)flush_stat_count,
                flush_stat_count ? (float)flush_stat_us / 1000.0f / (float)flush_stat_count : 0.0f,
                (float)flush_stat_max_us / 1000.0f, kpx_s);
  flush_stat_us = 0; flush_stat_px = 0; flush_stat_count = 0; flush_stat_max_us = 0;
  handler_stat_us = 0; handler_stat_max_us = 0; handler_stat_count = 0; handler_stat_slow = 0;
  draw_stat_since = now;
}
// ---------------------------------------------------------------------------
// Touch: read the GT911 directly instead of through TAMC_GT911::read()
//
// That function is a memory-safety hazard on a flaky bus. It returns void, so
// there is no way to see an I2C error, and it checks none of them either: a
// failed transfer leaves Wire.read() returning -1, which makes pointInfo 0xFF,
// so touches becomes 0xF = 15 and isTouched becomes true. It then runs
//     for (uint8_t i = 0; i < touches; i++) points[i] = readPoint(data);
// against `TP_Point points[5]` - ten elements past the end of a global object -
// and reports whatever that memory held as a touch coordinate. The visible
// symptom is a phantom finger pressed somewhere random, which swallows real taps,
// plus a UI that crawls because every failed transfer blocks for the Wire timeout
// and there are about seventeen of them per read.
//
// So the point-info byte is fetched here, validated, and only then acted on. A
// bad value means "no touch", never a press, and a run of failures recovers the
// bus instead of being ignored.
//
// setRotation(1) in setup_display() selects ROTATION_INVERTED, which the driver
// implements as the identity mapping (x = x, y = y), so the register values are
// already panel coordinates. If that rotation is ever changed, this must follow.
// ---------------------------------------------------------------------------
#define GT911_POINT_INFO_REG GT911_POINT_INFO  // 0x814E: flags + touch count
#define GT911_POINT_1_REG    GT911_POINT_1     // 0x814F: first point block
#define GT911_POINT_STRIDE   8                 // id(1) x(2) y(2) size(2) reserved(1)
#define GT911_MAX_TOUCHES    5                 // matches the driver's points[5]
// ~1/3 s of dead bus at LVGL's poll rate before trying to rescue it.
#define TOUCH_FAILS_BEFORE_RECOVER 10
#define TOUCH_RECOVER_INTERVAL_MS  10000UL

static uint32_t    touch_fail_streak = 0;
static unsigned long touch_last_recover_ms = 0;
static bool        touch_have_point = false;  // last good coordinates
static uint16_t    touch_x = 0;
static uint16_t    touch_y = 0;

// One register read, with the ACK and the byte count actually checked.
static bool gt911_read_reg(uint16_t reg, uint8_t *buf, uint8_t len) {
  Wire.beginTransmission(GT911_ADDR1);
  Wire.write((uint8_t)(reg >> 8));
  Wire.write((uint8_t)(reg & 0xFF));
  if (Wire.endTransmission() != 0) return false; // not ACKed
  if (Wire.requestFrom((uint8_t)GT911_ADDR1, len) != len) return false;
  for (uint8_t i = 0; i < len; i++) buf[i] = (uint8_t)Wire.read();
  return true;
}
static bool gt911_write_reg(uint16_t reg, uint8_t value) {
  Wire.beginTransmission(GT911_ADDR1);
  Wire.write((uint8_t)(reg >> 8));
  Wire.write((uint8_t)(reg & 0xFF));
  Wire.write(value);
  return Wire.endTransmission() == 0;
}
// A slave interrupted mid-transaction can hold SDA low, which wedges the bus until
// the power is removed - which is why a reboot, and even a reflash, does not clear
// it. Clocking SCL by hand lets the slave finish its byte and let go.
static void touch_bus_recover() {
  Wire.end();
  pinMode(TOUCH_GT911_SDA, INPUT_PULLUP);
  pinMode(TOUCH_GT911_SCL, OUTPUT);
  digitalWrite(TOUCH_GT911_SCL, HIGH);
  delayMicroseconds(5);
  for (int i = 0; i < 9 && digitalRead(TOUCH_GT911_SDA) == LOW; i++) {
    digitalWrite(TOUCH_GT911_SCL, LOW);
    delayMicroseconds(5);
    digitalWrite(TOUCH_GT911_SCL, HIGH);
    delayMicroseconds(5);
  }
  // A STOP condition, so the slave sees the transaction end cleanly.
  pinMode(TOUCH_GT911_SDA, OUTPUT);
  digitalWrite(TOUCH_GT911_SDA, LOW);
  delayMicroseconds(5);
  digitalWrite(TOUCH_GT911_SCL, HIGH);
  delayMicroseconds(5);
  digitalWrite(TOUCH_GT911_SDA, HIGH);
  delayMicroseconds(5);
  Wire.begin(TOUCH_GT911_SDA, TOUCH_GT911_SCL);
  // Public, and re-provisions the controller (address, resolution, config).
  ts.reset();
}
void my_touchpad_read(lv_indev_t *indev, lv_indev_data_t *data)
{
  (void)indev;
  uint8_t pointInfo = 0;
  const bool bus_ok = gt911_read_reg(GT911_POINT_INFO_REG, &pointInfo, 1);
  const uint8_t touches = pointInfo & 0x0F;
  const bool buffer_ready = (pointInfo >> 7) & 1;

  // The controller reports at most five points, so anything above that is a
  // corrupt byte rather than a report. This is the check the driver omits before
  // walking off the end of points[5].
  if (!bus_ok || touches > GT911_MAX_TOUCHES) {
    touch_fail_streak++;
    // Never leave the previous state standing: a stale PRESSED is exactly what
    // holds a phantom finger down and eats the next real tap. Drop the cached
    // point too, so a hiccup cannot be resumed from as a press at the old spot.
    touch_have_point = false;
    data->state = LV_INDEV_STATE_RELEASED;
    data->point.x = 0;
    data->point.y = 0;
    if (touch_fail_streak >= TOUCH_FAILS_BEFORE_RECOVER &&
        millis() - touch_last_recover_ms > TOUCH_RECOVER_INTERVAL_MS) {
      touch_last_recover_ms = millis();
      touch_fail_streak = 0;
      if (debug == 1) Serial.println("[TOUCH] bus not responding - recovering");
      touch_bus_recover();
    }
    return;
  }
  if (touch_fail_streak) {
    if (debug == 1) Serial.println("[TOUCH] responding again");
    touch_fail_streak = 0;
  }

  if (touches == 0) {
    // A lift arrives as "ready" with zero touches. Clear the flag so the next
    // scan can be reported, and forget the cached point.
    if (buffer_ready) gt911_write_reg(GT911_POINT_INFO_REG, 0);
    touch_have_point = false;
    data->state = LV_INDEV_STATE_RELEASED;
    return;
  }

  if (buffer_ready) {
    uint8_t raw[7];
    if (gt911_read_reg(GT911_POINT_1_REG, raw, sizeof(raw))) {
      const uint16_t x = (uint16_t)(raw[1] | (raw[2] << 8));
      const uint16_t y = (uint16_t)(raw[3] | (raw[4] << 8));
      // A partial read shows up as coordinates outside the panel; ignore them
      // rather than pressing at (0, 0) or wherever the garbage lands.
      if (x <= TOUCH_PANEL_W && y <= TOUCH_PANEL_H) {
        touch_x = x;
        touch_y = y;
        touch_have_point = true;
      }
    }
    // Acknowledge the scan whether or not the point block read back cleanly.
    gt911_write_reg(GT911_POINT_INFO_REG, 0);
  }
  // The ready bit is not set on every scan while a finger is held still, so fall
  // back to the last good coordinates. The driver behaved the same way (it kept
  // the previous points[] and only re-read them when the bit was set), and that
  // is what keeps press-and-hold and dragging working.
  if (!touch_have_point) {
    data->state = LV_INDEV_STATE_RELEASED;
    return;
  }
  data->state = LV_INDEV_STATE_PRESSED;
  data->point.x = touch_x;
  data->point.y = touch_y;
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
  pinMode(TFT_BL, OUTPUT);
  digitalWrite(TFT_BL, HIGH);
  Serial.println("Backlight set HIGH");
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
  // ---- draw buffer: where it lives matters more than how big it is ----------
  // Rendering touches each pixel several times (the background image blit, then
  // every widget drawn on top, and every alpha blend reads the destination back to
  // mix it). In PSRAM all of that goes through the cache and competes with the RGB
  // panel's DMA, which is reading 768KB per frame out of the same memory the whole
  // time. Internal SRAM has none of that contention.
  //
  // There is not room for 384KB of SRAM: the Settings meter reports roughly 173KB
  // free of the 320KB internal pool once WiFi and TLS are up, and LVGL's own 128KB
  // pool is a static array that does not even appear in that meter. So the SRAM
  // option is a SMALLER buffer - 48 rows is 76.8KB - which trades more flushes
  // (ten per full screen instead of two) for a much faster render. The bytes the
  // flush copies are the same either way, so what is lost is only per-flush
  // overhead and what is gained is every render pass.
  //
  //  * lv_display_set_buffers() wants the size in BYTES - it gets the height from
  //    buf_size / stride (see lv_display.c). An earlier version passed a pixel
  //    count, so LVGL used 60 rows of a 120-row buffer and half of it was dead.
  //  * One buffer, not two: my_disp_flush() copies synchronously and signals
  //    flush_ready before returning, so there is never a flush in flight for a
  //    second buffer to overlap with.
#define DRAW_BUF_PREFER_INTERNAL 1                    // 0 = always the big PSRAM buffer
#define DRAW_BUF_INTERNAL_ROWS   48                   // 800 * 48 * 2  = 76.8KB
#define DRAW_BUF_PSRAM_ROWS      240                  // 800 * 240 * 2 = 384KB
#define DRAW_BUF_RAM_RESERVE     (128 * 1024)         // left for WiFi/TLS afterwards
  bufSize = screenWidth * DRAW_BUF_PSRAM_ROWS; // pixels
#if DRAW_BUF_PREFER_INTERNAL
  {
    const size_t want = (size_t)screenWidth * DRAW_BUF_INTERNAL_ROWS * sizeof(lv_color_t);
    const size_t free_internal = heap_caps_get_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT);
    if (free_internal > want + DRAW_BUF_RAM_RESERVE) {
      lv_color_t *p = (lv_color_t *)heap_caps_malloc(want, MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT);
      if (p) {
        disp_draw_buf = p;
        bufSize = screenWidth * DRAW_BUF_INTERNAL_ROWS;
        Serial.printf("Draw buffer: %uKB in INTERNAL SRAM (%d rows) - %uKB was free, %uKB left\n",
                      (unsigned)(want / 1024), DRAW_BUF_INTERNAL_ROWS,
                      (unsigned)(free_internal / 1024),
                      (unsigned)((free_internal - want) / 1024));
      } else {
        Serial.println("Draw buffer: internal SRAM allocation failed, falling back to PSRAM");
      }
    } else {
      Serial.printf("Draw buffer: internal SRAM skipped - %uKB free, need %uKB + %uKB reserve\n",
                    (unsigned)(free_internal / 1024), (unsigned)(want / 1024),
                    (unsigned)(DRAW_BUF_RAM_RESERVE / 1024));
    }
  }
#endif
  size_t buffer_bytes = (size_t)bufSize * sizeof(lv_color_t); // sizeof(lv_color_t) == 2
  if (!disp_draw_buf) {
    bufSize = screenWidth * DRAW_BUF_PSRAM_ROWS;
    buffer_bytes = (size_t)bufSize * sizeof(lv_color_t);
    disp_draw_buf = (lv_color_t *)(psram_available ? heap_caps_malloc(buffer_bytes, MALLOC_CAP_SPIRAM) : heap_caps_malloc(buffer_bytes, MALLOC_CAP_8BIT));
    Serial.printf("Draw buffer: %uKB in %s (%d rows)\n", (unsigned)(buffer_bytes / 1024),
                  psram_available ? "PSRAM" : "SRAM", (int)(bufSize / screenWidth));
  }
  if (!disp_draw_buf)
  {
    Serial.println("Failed to allocate LVGL display buffer! Halting...");
    while (true) delay(1000); // Halt execution
  }
  Serial.println("LVGL display buffer allocated");
  Serial.printf("Free heap after buffer: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));
  Serial.printf("Free internal heap: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT));
  Serial.printf("Free PSRAM after buffer: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
  disp = lv_display_create(screenWidth, screenHeight);
  lv_display_set_flush_cb(disp, my_disp_flush);
  // Single buffer, partial mode, size in bytes.
  lv_display_set_buffers(disp, disp_draw_buf, NULL, buffer_bytes, LV_DISPLAY_RENDER_MODE_PARTIAL);
  Serial.println("LVGL display created");
  lv_indev_t *indev = lv_indev_create();
  lv_indev_set_type(indev, LV_INDEV_TYPE_POINTER);
  lv_indev_set_read_cb(indev, my_touchpad_read);
  Serial.println("Touch input device created");
  Serial.println("Display setup complete.");
}
void loop_display()
{
  const uint32_t t0 = micros();
  lv_task_handler();
  const uint32_t spent = micros() - t0;
  handler_stat_us += spent;
  handler_stat_count++;
  if (spent > handler_stat_max_us) handler_stat_max_us = spent;
  // LVGL's refresh period is 33ms, so anything past that is a dropped frame.
  if (spent > 33000UL) handler_stat_slow++;
  reportDrawStats();
  delay(5);
}
#endif // DISPLAY_H