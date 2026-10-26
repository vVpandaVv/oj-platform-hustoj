#ifndef OJ_IMAGE_SPJ_PROFILE_HPP
#define OJ_IMAGE_SPJ_PROFILE_HPP

// These values are trusted problem settings, never read from student output.
#ifndef OJ_IMAGE_COLOR_TOLERANCE
#define OJ_IMAGE_COLOR_TOLERANCE 16
#endif
#ifndef OJ_IMAGE_SPATIAL_RADIUS
#define OJ_IMAGE_SPATIAL_RADIUS 0
#endif
#ifndef OJ_IMAGE_MAX_UNMATCHED_PIXELS
#define OJ_IMAGE_MAX_UNMATCHED_PIXELS 0
#endif

namespace image_spj {
constexpr int color_tolerance = OJ_IMAGE_COLOR_TOLERANCE;
// Radius 1 or 2 also permits small translations; leave at 0 for position tasks.
constexpr int spatial_radius = OJ_IMAGE_SPATIAL_RADIUS;
constexpr unsigned max_unmatched_pixels = OJ_IMAGE_MAX_UNMATCHED_PIXELS;
constexpr unsigned max_dimension = 2048;
constexpr unsigned long long max_pixels = 4194304;
constexpr unsigned max_png_bytes = 8 * 1024 * 1024;
constexpr unsigned max_reference_bytes = 12 * 1024 * 1024;
constexpr unsigned background = 255;
constexpr const char* reference_header = "OJ_IMAGE_PNG_BASE64_V1\n";
constexpr const char* settings_file_name = "image-spj-settings.txt";
constexpr int settings_max_color_tolerance = 96;
constexpr const char* capture_marker_name = "capture-armed.txt";
constexpr const char* capture_directory_name = "_capture";
constexpr long long capture_window_seconds = 600;
static_assert(color_tolerance >= 0 && color_tolerance <= 64, "Invalid color tolerance");
static_assert(spatial_radius >= 0 && spatial_radius <= 2, "Invalid spatial radius");
static_assert(OJ_IMAGE_MAX_UNMATCHED_PIXELS >= 0, "Invalid unmatched pixel allowance");
}
#endif
