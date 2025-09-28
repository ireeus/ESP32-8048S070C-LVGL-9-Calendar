#include <TFT_eSPI.h>

TFT_eSPI tft = TFT_eSPI();

void setup() {
  Serial.begin(115200);
  delay(1000); // Allow Serial to stabilize
  Serial.println("Starting TFT test...");
  Serial.printf("Free heap before init: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));

  tft.begin();
  Serial.println("TFT initialized");

  tft.fillScreen(TFT_BLUE); // Test with blue screen
  Serial.println("Screen filled blue");

  // Turn on backlight
  pinMode(2, OUTPUT); // Backlight pin
  digitalWrite(2, HIGH);
  Serial.println("Backlight set HIGH");

  Serial.printf("Free heap after init: %d bytes\n", heap_caps_get_free_size(MALLOC_CAP_8BIT));
}

void loop() {
  delay(1000);
  Serial.println("Running...");
}