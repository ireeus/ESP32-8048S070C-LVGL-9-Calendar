# Third-party assets

## Meteocons weather icons

The weather icons in `src/img/` (the 22 files whose symbols are declared in
`src/main.cpp` around `extern const lv_image_dsc_t Clear_sky;`) are derived from
**Meteocons v3** by Bas Milius.

- Source: https://github.com/basmilius/weather-icons
- Homepage: https://meteocons.com/
- Licence: MIT (see below)
- What we changed: selected 22 icons, rasterised them at 68x68 px with
  `rsvg-convert`, and converted them to LVGL 9 `ARGB8888` image descriptors.
  No artwork was redrawn. The `svg-static` variants are used because the
  animated originals hide their precipitation behind CSS animations that
  non-browser rasterisers drop.

The SVG sources are cached under `tools/.meteocons-cache/` and the conversion is
reproducible with `tools/make-weather-icons.py`.

```
MIT License

Copyright (c) 2020-2024 Bas Milius

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

The same notice is repeated at the top of each generated `src/img/*.c` file.
