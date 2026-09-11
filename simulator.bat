@echo off
setlocal enabledelayedexpansion

echo Setting up LVGL PC Simulator for Windows with Visual Studio...
echo.

:: Check if Git is installed
git --version >nul 2>&1
if %errorlevel% neq 0 (
    echo Error: Git for Windows is required. Download from https://git-scm.com/download/win
    pause
    exit /b 1
)

:: Set up directory
set SIM_DIR=C:\lvgl_simulator
if exist "%SIM_DIR%" (
    echo Simulator directory exists. Removing old version...
    rmdir /s /q "%SIM_DIR%"
)
mkdir "%SIM_DIR%"
cd /d "%SIM_DIR%"

:: Clone the repo with submodules
echo Cloning LVGL Visual Studio port...
git clone --recurse-submodules https://github.com/lvgl/lv_port_pc_visual_studio.git .
if %errorlevel% neq 0 (
    echo Error cloning repo. Check internet connection.
    pause
    exit /b 1
)

echo.
set /p PROJECT_PATH="Enter path to your LVGL project's main.ccp (e.g., C:\my_lvgl_project\main.ccp): "
if not exist "%PROJECT_PATH%" (
    echo Error: main.ccp not found at %PROJECT_PATH%
    pause
    exit /b 1
)

:: Copy main.ccp to the simulator's entry point (rename to .cpp if needed, but keep as .c for C compatibility)
copy "%PROJECT_PATH%" "LvglWindowsSimulator\main.ccp" >nul
if %errorlevel% neq 0 (
    echo Error copying main.ccp
    pause
    exit /b 1
)

:: Ask for entire project folder (optional)
set /p PROJECT_FOLDER="Enter path to your entire LVGL project folder (optional, press Enter to skip): "
if not "%PROJECT_FOLDER%"=="" (
    if exist "%PROJECT_FOLDER%\src" (
        echo Copying project sources from %PROJECT_FOLDER%\src to LvglWindowsSimulator\my_project\
        mkdir "LvglWindowsSimulator\my_project" >nul 2>&1
        xcopy "%PROJECT_FOLDER%\src\*.*" "LvglWindowsSimulator\my_project\" /s /y >nul
    ) else (
        echo Warning: No 'src' subfolder found in project. Copying root files...
        xcopy "%PROJECT_FOLDER%\*.ccp" "LvglWindowsSimulator\" /y >nul 2>&1
        xcopy "%PROJECT_FOLDER%\*.c" "LvglWindowsSimulator\" /y >nul 2>&1
        xcopy "%PROJECT_FOLDER%\*.h" "LvglWindowsSimulator\" /y >nul 2>&1
    )
)

:: Update the entry point to call your main (simple template replacement)
echo Updating LvglWindowsSimulator.cpp to call your main()...
(
echo #include ^<windows.h^>
echo #include "lvgl/lvgl.h"
echo #include "main.ccp"  // Your custom LVGL code
echo.
echo int WINAPI WinMain^(HINSTANCE hInstance, HINSTANCE hPrevInstance, LPSTR lpCmdLine, int nCmdShow^) {
echo     // LVGL Simulator init ^(from original port^)
echo     lv_init^(^);
echo     // Add your display and input drivers here if not in main.ccp ^(see lv_port_pc_visual_studio docs^)
echo.
echo     // Call your custom main
echo     main^(^);
echo.
echo     while ^(1^) {
echo         lv_timer_handler^(^);  // Handle LVGL tasks
echo         Sleep^(5^);  // Small delay
echo     }
echo     return 0;
echo }
) > "LvglWindowsSimulator\LvglWindowsSimulator.cpp"

echo.
echo Setup complete! 
echo 1. Open Visual Studio.
echo 2. File ^> Open ^> Project/Solution, select %SIM_DIR%\LVGL.sln
echo 3. Set 'LvglWindowsSimulator' as startup project ^(right-click ^> Set as Startup Project^).
echo 4. Build ^(Ctrl+Shift+B^), then run ^(F5^). A window should open with your LVGL UI.
echo.
echo Tips:
echo - Edit lv_conf.h in the project to enable demos/features ^(e.g., #define LV_USE_DEMO_WIDGETS 1^).
echo - For custom drivers, see https://docs.lvgl.io/master/integration/ports/pc-sim.html
echo - If errors, ensure LV_MEM_SIZE >= 128KB in lv_conf.h.
echo.
pause