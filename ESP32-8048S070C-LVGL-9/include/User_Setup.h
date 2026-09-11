#define ESP32
#define TFT_eSPI_PLATFORMIO

// Assume ILI9488 driver (verify with board documentation)
#define ILI9488_DRIVER

// SPI pins (adjust based on schematic)
#define TFT_MISO 12
#define TFT_MOSI 13
#define TFT_SCLK 14
#define TFT_CS   15
#define TFT_DC   21
#define TFT_RST  -1
#define TFT_BL   2

// Touch configuration
#define TOUCH_CS -1 // GT911 uses I2C
#define TOUCH_SDA 19
#define TOUCH_SCL 20
#define TOUCH_INT -1
#define TOUCH_RST 38
#define TOUCH_DRIVER GT911

// Screen dimensions
#define TFT_WIDTH  800
#define TFT_HEIGHT 480

// SPI frequency
#define SPI_FREQUENCY 20000000 // 20 MHz