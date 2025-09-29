/**
 * @file lv_conf.h
 * Configuration file for v9.3.0
 */

/* clang-format off */
#if 1 /* Set this to "1" to enable content */

#ifndef LV_CONF_H
#define LV_CONF_H

/*====================
   COLOR SETTINGS
 *====================*/

#define LV_COLOR_DEPTH 16

/*=========================
   STDLIB WRAPPER SETTINGS
 *=========================*/

#define LV_USE_STDLIB_MALLOC    LV_STDLIB_BUILTIN
#define LV_USE_STDLIB_STRING    LV_STDLIB_BUILTIN
#define LV_USE_STDLIB_SPRINTF   LV_STDLIB_BUILTIN

#define LV_STDINT_INCLUDE       <stdint.h>
#define LV_STDDEF_INCLUDE       <stddef.h>
#define LV_STDBOOL_INCLUDE      <stdbool.h>
#define LV_INTTYPES_INCLUDE     <inttypes.h>
#define LV_LIMITS_INCLUDE       <limits.h>
#define LV_STDARG_INCLUDE       <stdarg.h>

#if LV_USE_STDLIB_MALLOC == LV_STDLIB_BUILTIN
    #define LV_MEM_SIZE (128 * 1024U)  // Increased to 128KB
    #define LV_MEM_POOL_EXPAND_SIZE 0
    #define LV_MEM_ADR 0
    #if LV_MEM_ADR == 0
        #undef LV_MEM_POOL_INCLUDE
        #undef LV_MEM_POOL_ALLOC
    #endif
#endif

/*====================
   HAL SETTINGS
 *====================*/

#define LV_DEF_REFR_PERIOD  16
#define LV_DPI_DEF 130

/*=================
 * OPERATING SYSTEM
 *=================*/

#define LV_USE_OS   LV_OS_NONE

/*========================
 * RENDERING CONFIGURATION
 *========================*/

#define LV_DRAW_BUF_STRIDE_ALIGN  1
#define LV_DRAW_BUF_ALIGN         4
#define LV_DRAW_TRANSFORM_USE_MATRIX  0
#define LV_DRAW_LAYER_SIMPLE_BUF_SIZE (24 * 1024)
#define LV_DRAW_LAYER_MAX_MEMORY 0
#define LV_DRAW_THREAD_STACK_SIZE (8 * 1024)
#define LV_DRAW_THREAD_PRIO LV_THREAD_PRIO_HIGH

#define LV_USE_DRAW_SW 1
#if LV_USE_DRAW_SW == 1
    #define LV_DRAW_SW_SUPPORT_RGB565       1
    #define LV_DRAW_SW_SUPPORT_RGB565_SWAPPED 1
    #define LV_DRAW_SW_SUPPORT_RGB888       1
    #define LV_DRAW_SW_SUPPORT_XRGB8888     1
    #define LV_DRAW_SW_SUPPORT_ARGB8888     1
    #define LV_DRAW_SW_SUPPORT_ARGB8888_PREMULTIPLIED 1
    #define LV_DRAW_SW_SUPPORT_L8           1
    #define LV_DRAW_SW_SUPPORT_AL88         1
    #define LV_DRAW_SW_SUPPORT_A8           1
    #define LV_DRAW_SW_SUPPORT_I1           1
    #define LV_DRAW_SW_I1_LUM_THRESHOLD 127
    #define LV_DRAW_SW_DRAW_UNIT_CNT    1
    #define LV_USE_DRAW_ARM2D_SYNC      0
    #define LV_USE_NATIVE_HELIUM_ASM    0
    #define LV_DRAW_SW_COMPLEX          1
    #if LV_DRAW_SW_COMPLEX == 1
        #define LV_DRAW_SW_SHADOW_CACHE_SIZE 0
        #define LV_DRAW_SW_CIRCLE_CACHE_SIZE 4
    #endif
    #define LV_USE_DRAW_SW_ASM     LV_DRAW_SW_ASM_NONE
    #define LV_USE_DRAW_SW_COMPLEX_GRADIENTS    0
#endif

#define LV_USE_NEMA_GFX 0
#define LV_USE_DRAW_VGLITE 0
#define LV_USE_PXP 0
#define LV_USE_DRAW_G2D 0
#define LV_USE_DRAW_DAVE2D 0
#define LV_USE_DRAW_SDL 0
#define LV_USE_DRAW_VG_LITE 0

/*==================
 * FEATURE CONFIGURATION
 *==================*/

#define LV_USE_LOG 0
#define LV_USE_ASSERT 0
#define LV_USE_FREETYPE 0
#define LV_USE_LIBPNG 0
#define LV_USE_LIBJPEG_TURBO 0
#define LV_USE_BMP 0
#define LV_USE_GIF 0
#define LV_USE_SJPG 0
#define LV_USE_QRCODE 0
#define LV_USE_LOTTIE 0
#define LV_USE_FFMPEG 0
#define LV_USE_TINY_TTF 0
#define LV_USE_THORVG 0
#define LV_USE_RLE 0
#define LV_USE_SNAPSHOT 0
#define LV_USE_PERF_MONITOR 0
#define LV_USE_PROFILER 0
#define LV_USE_MONKEY 0
#define LV_USE_GRIDNAV 0
#define LV_USE_FRAGMENT 0
#define LV_USE_IMGFONT 0
#define LV_USE_OBSERVER 1
#define LV_USE_IME_PINYIN 0
#define LV_USE_FILE_EXPLORER 0
#define LV_USE_FONT_MANAGER 0
#define LV_USE_TEST 0
#define LV_USE_COLOR_FILTER 0


/*==================
 * DEVICES
 *==================*/

#define LV_USE_SDL 0
#define LV_USE_X11 0
#define LV_USE_WAYLAND 0
#define LV_USE_LINUX_FBDEV 0
#define LV_USE_NUTTX 0
#define LV_USE_LINUX_DRM 0
#define LV_USE_TFT_ESPI 0
#define LV_USE_EVDEV 0
#define LV_USE_LIBINPUT 0
#define LV_USE_ST7735 0
#define LV_USE_ST7789 0
#define LV_USE_ST7796 0
#define LV_USE_ILI9341 0
#define LV_USE_FT81X 0
#define LV_USE_GENERIC_MIPI 0
#define LV_USE_RENESAS_GLCDC 0
#define LV_USE_ST_LTDC 0
#define LV_USE_WINDOWS 0
#define LV_USE_UEFI 0
#define LV_USE_OPENGLES 0
#define LV_USE_QNX 0

/*=====================
 * BUILD OPTIONS
 *======================*/

#define LV_BUILD_EXAMPLES 1
#define LV_BUILD_DEMOS 1

#if LV_BUILD_DEMOS
    #define LV_USE_DEMO_WIDGETS 0
    #define LV_USE_DEMO_KEYPAD_AND_ENCODER 0
    #define LV_USE_DEMO_BENCHMARK 0
    #define LV_USE_DEMO_RENDER 0
    #define LV_USE_DEMO_STRESS 0
    #define LV_USE_DEMO_MUSIC 0
    #define LV_USE_DEMO_VECTOR_GRAPHIC 0
    #define LV_USE_DEMO_FLEX_LAYOUT 0
    #define LV_USE_DEMO_MULTILANG 0
    #define LV_USE_DEMO_TRANSFORM 0
    #define LV_USE_DEMO_SCROLL 0
    #define LV_USE_DEMO_EBIKE 0
    #define LV_USE_DEMO_HIGH_RES 0
    #define LV_USE_DEMO_SMARTWATCH 0
#endif

/*--END OF LV_CONF_H--*/

#endif /*LV_CONF_H*/

#endif /*End of "Content enable"*/