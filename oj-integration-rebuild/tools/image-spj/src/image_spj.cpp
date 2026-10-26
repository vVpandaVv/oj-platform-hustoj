#include "profile.hpp"
#include "decoder_config.hpp"
#include "../vendor/lodepng/lodepng.h"
#include "../vendor/lodepng/lodepng.cpp"

#include <algorithm>
#include <array>
#include <cerrno>
#include <chrono>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <filesystem>
#include <fstream>
#include <memory>
#include <stdexcept>
#include <string>
#include <vector>
#ifndef _WIN32
#include <csignal>
#include <fcntl.h>
#include <sys/resource.h>
#include <sys/stat.h>
#include <unistd.h>
#endif

namespace image_spj {
using Bytes = std::vector<unsigned char>;
using Clock = std::chrono::steady_clock;
struct InvalidImage : std::runtime_error { using std::runtime_error::runtime_error; };
struct Pixel { unsigned char r, g, b; };
struct Image { unsigned width, height; std::vector<Pixel> pixels; };
struct Comparison {
    std::size_t missing = 0, extra = 0;
    int min_x = -1, min_y = -1, max_x = -1, max_y = -1, max_delta = 0;
};

double milliseconds(Clock::time_point start) {
    return std::chrono::duration<double, std::milli>(Clock::now() - start).count();
}

#ifndef _WIN32
void budget_exceeded(int) {
    const char message[] = "OJ_IMAGE_SPJ/1 CHECKER_ERROR reason=checker_timeout\n";
    const auto ignored = write(STDERR_FILENO, message, sizeof(message) - 1);
    (void)ignored;
    _exit(3);
}

void lower_limit(decltype(RLIMIT_AS) resource, rlim_t requested) {
    struct rlimit limit {};
    if (getrlimit(resource, &limit) != 0) throw std::runtime_error("getrlimit_failed");
    limit.rlim_cur = std::min(limit.rlim_cur, requested);
    if (setrlimit(resource, &limit) != 0) throw std::runtime_error("setrlimit_failed");
}
#endif

void set_limits() {
#ifndef _WIN32
    lower_limit(RLIMIT_AS, 256ULL * 1024 * 1024);
    lower_limit(RLIMIT_CPU, 3);
    lower_limit(RLIMIT_CORE, 0);
    std::signal(SIGXCPU, budget_exceeded);
    std::signal(SIGALRM, budget_exceeded);
    const unsigned previous = alarm(0);
    alarm(previous == 0 ? 5 : std::min(previous, 5U));
#endif
}

Bytes read_regular_file(const char* path, std::size_t maximum) {
#ifdef _WIN32
    std::error_code ec;
    if (std::filesystem::symlink_status(path, ec).type() != std::filesystem::file_type::regular || ec)
        throw InvalidImage("not_regular_file");
    std::ifstream file(path, std::ios::binary | std::ios::ate);
    if (!file) throw InvalidImage("file_unreadable");
    const auto end = file.tellg();
    if (end <= 0 || static_cast<unsigned long long>(end) > maximum)
        throw InvalidImage("file_size_limit_or_empty");
    Bytes data(static_cast<std::size_t>(end));
    file.seekg(0);
    file.read(reinterpret_cast<char*>(data.data()), static_cast<std::streamsize>(data.size()));
    if (!file || file.peek() != std::char_traits<char>::eof()) throw InvalidImage("file_changed");
    return data;
#else
    const int fd = open(path, O_RDONLY | O_NOFOLLOW | O_NONBLOCK | O_CLOEXEC);
    if (fd < 0) throw InvalidImage("file_unreadable_or_symlink");
    struct File { int fd; ~File() { close(fd); } } file {fd};
    struct stat status {};
    if (fstat(fd, &status) != 0 || !S_ISREG(status.st_mode)) throw InvalidImage("not_regular_file");
    if (status.st_size <= 0 || static_cast<unsigned long long>(status.st_size) > maximum)
        throw InvalidImage("file_size_limit_or_empty");
    Bytes data(static_cast<std::size_t>(status.st_size));
    std::size_t offset = 0;
    while (offset < data.size()) {
        const ssize_t count = read(fd, data.data() + offset, data.size() - offset);
        if (count < 0 && errno == EINTR) continue;
        if (count <= 0) throw InvalidImage("file_read_failed");
        offset += static_cast<std::size_t>(count);
    }
    unsigned char extra;
    ssize_t count;
    do { count = read(fd, &extra, 1); } while (count < 0 && errno == EINTR);
    if (count != 0) throw InvalidImage("file_changed");
    return data;
#endif
}

int base64_value(unsigned char c) {
    if (c >= 'A' && c <= 'Z') return c - 'A';
    if (c >= 'a' && c <= 'z') return c - 'a' + 26;
    if (c >= '0' && c <= '9') return c - '0' + 52;
    if (c == '+') return 62;
    if (c == '/') return 63;
    return -1;
}

Bytes unwrap_reference(Bytes data) {
    // Only trusted .out files accept the text envelope. Student stdout must be PNG.
    const std::string lf_header = reference_header;
    const std::string crlf_header = lf_header.substr(0, lf_header.size() - 1) + "\r\n";
    auto begins = [&](const std::string& header) {
        return data.size() >= header.size() && std::equal(header.begin(), header.end(), data.begin());
    };
    const std::size_t header_size = begins(lf_header) ? lf_header.size()
        : (begins(crlf_header) ? crlf_header.size() : 0);
    if (header_size == 0) return data;
    std::string text;
    text.reserve(data.size() - header_size);
    for (std::size_t i = header_size; i < data.size(); ++i) {
        const auto c = data[i];
        if (c == '\n' || c == '\r' || c == ' ' || c == '\t') continue;
        if (c != '=' && base64_value(c) < 0) throw InvalidImage("invalid_reference_base64");
        text.push_back(static_cast<char>(c));
    }
    if (text.empty() || text.size() % 4 != 0) throw InvalidImage("invalid_reference_base64");
    Bytes png;
    for (std::size_t i = 0; i < text.size(); i += 4) {
        const int a = base64_value(text[i]), b = base64_value(text[i + 1]);
        const bool pad_c = text[i + 2] == '=', pad_d = text[i + 3] == '=';
        const int c = pad_c ? 0 : base64_value(text[i + 2]);
        const int d = pad_d ? 0 : base64_value(text[i + 3]);
        if (a < 0 || b < 0 || c < 0 || d < 0 || (pad_c && !pad_d)
            || ((pad_c || pad_d) && i + 4 != text.size())
            || (pad_c && (b & 15)) || (pad_d && !pad_c && (c & 3)))
            throw InvalidImage("invalid_reference_base64");
        png.push_back(static_cast<unsigned char>((a << 2) | (b >> 4)));
        if (!pad_c) png.push_back(static_cast<unsigned char>((b << 4) | (c >> 2)));
        if (!pad_d) png.push_back(static_cast<unsigned char>((c << 6) | d));
        if (png.size() > max_png_bytes) throw InvalidImage("png_file_size_limit");
    }
    return png;
}

void validate_chunks(const Bytes& png) {
    if (png.size() > max_png_bytes) throw InvalidImage("png_file_size_limit");
    const unsigned char signature[] = {137, 80, 78, 71, 13, 10, 26, 10};
    if (png.size() < 33 || !std::equal(std::begin(signature), std::end(signature), png.begin()))
        throw InvalidImage("not_png");
    std::size_t offset = 8, chunks = 0;
    bool data_seen = false;
    while (offset < png.size()) {
        if (png.size() - offset < 12 || ++chunks > 10000) throw InvalidImage("invalid_png_chunks");
        const auto* chunk = png.data() + offset;
        const std::size_t length = lodepng_chunk_length(chunk);
        if (length > png.size() - offset - 12) throw InvalidImage("truncated_png_chunk");
        if (lodepng_chunk_check_crc(chunk)) throw InvalidImage("png_crc_error");
        if (lodepng_chunk_type_equals(chunk, "acTL") || lodepng_chunk_type_equals(chunk, "fcTL")
            || lodepng_chunk_type_equals(chunk, "fdAT")) throw InvalidImage("animated_png_not_supported");
        if (chunks == 1 && (!lodepng_chunk_type_equals(chunk, "IHDR") || length != 13))
            throw InvalidImage("invalid_png_header");
        if (chunks > 1 && lodepng_chunk_type_equals(chunk, "IHDR")) throw InvalidImage("duplicate_png_header");
        if (lodepng_chunk_type_equals(chunk, "IDAT")) data_seen = true;
        offset += length + 12;
        if (lodepng_chunk_type_equals(chunk, "IEND")) {
            if (length != 0 || !data_seen || offset != png.size()) throw InvalidImage("invalid_png_end");
            return;
        }
    }
    throw InvalidImage("missing_png_end");
}

std::string encode_reference(const Bytes& png) {
    static const char alphabet[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string text;
    text.reserve(png.size() / 3 * 4 + 8);
    std::size_t column = 0;
    auto put = [&](char c) {
        text.push_back(c);
        if (++column == 120) { text.push_back('\n'); column = 0; }
    };
    for (std::size_t i = 0; i < png.size(); i += 3) {
        const unsigned a = png[i];
        const unsigned b = i + 1 < png.size() ? png[i + 1] : 0;
        const unsigned c = i + 2 < png.size() ? png[i + 2] : 0;
        put(alphabet[a >> 2]);
        put(alphabet[((a & 3) << 4) | (b >> 4)]);
        put(i + 1 < png.size() ? alphabet[((b & 15) << 2) | (c >> 6)] : '=');
        put(i + 2 < png.size() ? alphabet[c & 63] : '=');
    }
    if (column != 0) text.push_back('\n');
    return text;
}

Image decode(const Bytes& png) {
    validate_chunks(png);
    struct State {
        LodePNGState value;
        State() { lodepng_state_init(&value); }
        ~State() { lodepng_state_cleanup(&value); }
    } state;
    unsigned width = 0, height = 0;
    if (lodepng_inspect(&width, &height, &state.value, png.data(), png.size()))
        throw InvalidImage("invalid_png_header");
    if (width == 0 || height == 0 || width > max_dimension || height > max_dimension
        || static_cast<unsigned long long>(width) * height > max_pixels)
        throw InvalidImage("image_dimensions_limit");
    if (state.value.info_png.color.bitdepth > 8) throw InvalidImage("use_8bit_png");
    state.value.info_raw.colortype = LCT_RGBA;
    state.value.info_raw.bitdepth = 8;
    state.value.decoder.zlibsettings.max_output_size = (static_cast<std::size_t>(width) * 4 + 16) * height + 1024;
    unsigned char* raw = nullptr;
    const unsigned error = lodepng_decode(&raw, &width, &height, &state.value, png.data(), png.size());
    std::unique_ptr<unsigned char, decltype(&std::free)> owned(raw, &std::free);
    if (error) throw InvalidImage("png_decode_" + std::to_string(error));
    Image image {width, height, {}};
    image.pixels.resize(static_cast<std::size_t>(width) * height);
    for (std::size_t i = 0; i < image.pixels.size(); ++i) {
        const auto* source = raw + i * 4;
        const unsigned alpha = source[3];
        auto composite = [&](unsigned color) {
            return static_cast<unsigned char>((color * alpha + background * (255 - alpha) + 127) / 255);
        };
        image.pixels[i] = {composite(source[0]), composite(source[1]), composite(source[2])};
    }
    return image;
}

int g_color_tolerance = color_tolerance;
// Set when a settings file exists but this process may not read it. The strict default then applies so
// students are never turned into runtime errors by a permission slip, and the verdict says so.
std::string g_settings_note;

bool close_color(const Pixel& a, const Pixel& b) {
    return std::abs(int(a.r) - int(b.r)) <= g_color_tolerance
        && std::abs(int(a.g) - int(b.g)) <= g_color_tolerance
        && std::abs(int(a.b) - int(b.b)) <= g_color_tolerance;
}

bool nearby_match(const Pixel& pixel, const Image& other, int x, int y) {
    for (int yy = std::max(0, y - spatial_radius); yy <= std::min(int(other.height) - 1, y + spatial_radius); ++yy)
        for (int xx = std::max(0, x - spatial_radius); xx <= std::min(int(other.width) - 1, x + spatial_radius); ++xx)
            if (close_color(pixel, other.pixels[static_cast<std::size_t>(yy) * other.width + xx])) return true;
    return false;
}

Comparison compare(const Image& expected, const Image& actual) {
    Comparison result;
    for (unsigned y = 0; y < expected.height; ++y) {
        for (unsigned x = 0; x < expected.width; ++x) {
            const std::size_t i = static_cast<std::size_t>(y) * expected.width + x;
            if (close_color(expected.pixels[i], actual.pixels[i])) continue;
            const Pixel& e = expected.pixels[i];
            const Pixel& a = actual.pixels[i];
            result.max_delta = std::max({result.max_delta, std::abs(int(e.r) - int(a.r)),
                std::abs(int(e.g) - int(a.g)), std::abs(int(e.b) - int(a.b))});
            if (result.max_x < 0) { result.min_x = int(x); result.min_y = int(y); result.max_x = int(x); result.max_y = int(y); }
            result.min_x = std::min(result.min_x, int(x)); result.max_x = std::max(result.max_x, int(x));
            result.min_y = std::min(result.min_y, int(y)); result.max_y = std::max(result.max_y, int(y));
            // Both directions prevent an extra or missing isolated mark from passing.
            if (!nearby_match(expected.pixels[i], actual, int(x), int(y))) ++result.missing;
            if (!nearby_match(actual.pixels[i], expected, int(x), int(y))) ++result.extra;
        }
    }
    return result;
}

bool valid_case_name(const std::string& name) {
    if (name.empty() || name.size() > 64 || !std::isalnum(static_cast<unsigned char>(name[0]))) return false;
    for (const char c : name)
        if (!std::isalnum(static_cast<unsigned char>(c)) && c != '_' && c != '-') return false;
    return true;
}

std::string g_reference_path;
std::string g_case_name = "case";

// Best effort side files next to the capture candidates so an administrator can see why a submission
// was rejected. They never change the verdict and silently do nothing without the capture folder.
bool write_side_file(const std::string& suffix, const std::string& contents, bool remove_only) noexcept {
    try {
        namespace fs = std::filesystem;
        std::error_code ec;
        if (g_reference_path.empty()) return false;
        const fs::path directory = fs::weakly_canonical(fs::absolute(g_reference_path, ec), ec).parent_path();
        if (ec) return false;
        const std::string problem = directory.filename().string();
        if (problem.empty() || problem.size() > 12) return false;
        for (const char c : problem)
            if (!std::isdigit(static_cast<unsigned char>(c))) return false;
        const fs::path capture_directory = directory.parent_path() / capture_directory_name;
        if (!fs::is_directory(capture_directory, ec) || ec) return false;
        const fs::path target = capture_directory / (problem + suffix);
        if (remove_only) {
            fs::remove(target, ec);
            return true;
        }
        const fs::path temporary = capture_directory / ("." + problem + suffix + ".tmp");
        {
            std::ofstream out(temporary, std::ios::binary | std::ios::trunc);
            if (!out) return false;
            out.write(contents.data(), static_cast<std::streamsize>(contents.size()));
            out.close();
            if (!out) return false;
        }
        fs::permissions(temporary,
            fs::perms::owner_read | fs::perms::owner_write | fs::perms::group_read | fs::perms::others_read,
            fs::perm_options::replace, ec);
        fs::rename(temporary, target, ec);
        return !ec;
    } catch (...) {
        return false;
    }
}

std::string read_text_file_for_side(const char* path) noexcept {
    try {
        const auto bytes = read_regular_file(path, max_png_bytes);
        return std::string(bytes.begin(), bytes.end());
    } catch (...) {
        return std::string();
    }
}

void log_verdict(const std::string& line) noexcept {
    write_side_file(".last.txt", std::to_string(static_cast<long long>(std::time(nullptr))) + " " + line, false);
}

void emit(const std::string& message) {
    std::string line = message;
    if (!g_settings_note.empty() && !line.empty() && line.back() == char(10)) {
        line.pop_back();
        line += " settings=" + g_settings_note;
        line.push_back(char(10));
    }
    std::fputs(line.c_str(), stderr);
    log_verdict(line);
}

// Reference capture: while a trusted, short-lived marker file sits in the (read-only) problem data
// folder, the first valid PNG written by a submission is stored as a candidate reference in a separate
// capture folder next to the problem folders, instead of being compared. The problem folder is never
// written. A person still has to review and apply the candidate in the platform.
bool try_capture(const char* input_path, const char* reference_path, const char* actual_path,
                 Clock::time_point start) {
    namespace fs = std::filesystem;
    std::error_code ec;
    const fs::path directory = fs::weakly_canonical(fs::absolute(reference_path, ec), ec).parent_path();
    if (ec) return false;
    const fs::path marker = directory / capture_marker_name;
    if (fs::symlink_status(marker, ec).type() != fs::file_type::regular || ec) return false;
    const auto marked = fs::last_write_time(marker, ec);
    if (ec) return false;
    const auto age = fs::file_time_type::clock::now() - marked;
    if (age > std::chrono::seconds(capture_window_seconds) || age < -std::chrono::seconds(60)) return false;
    std::string problem = directory.filename().string();
    if (problem.empty() || problem.size() > 12) return false;
    for (const char c : problem)
        if (!std::isdigit(static_cast<unsigned char>(c))) return false;
    const fs::path capture_directory = directory.parent_path() / capture_directory_name;
    const fs::path target = capture_directory / (problem + ".txt");
    const auto existing = fs::symlink_status(target, ec);
    if (!ec && existing.type() == fs::file_type::regular) {
        const auto captured = fs::last_write_time(target, ec);
        if (!ec && captured >= marked) return false;  // This marker already produced its candidate.
    }
    Bytes png;
    Image image;
    try { png = read_regular_file(actual_path, max_png_bytes); image = decode(png); }
    catch (const InvalidImage&) { return false; }
    std::string name = fs::path(input_path).stem().string();
    if (!valid_case_name(name)) name = "case";
    const std::string text = std::string("OJ_IMAGE_CAPTURE_V1 ") + name + "\n" + reference_header + encode_reference(png);
    const fs::path temporary = capture_directory / ("." + problem + ".tmp");
    {
        std::ofstream out(temporary, std::ios::binary | std::ios::trunc);
        if (!out) throw std::runtime_error("capture_dir_not_writable");
        out.write(text.data(), static_cast<std::streamsize>(text.size()));
        out.close();
        if (!out) throw std::runtime_error("capture_write_failed");
    }
    fs::permissions(temporary,
        fs::perms::owner_read | fs::perms::owner_write | fs::perms::group_read | fs::perms::others_read,
        fs::perm_options::replace, ec);
    fs::rename(temporary, target, ec);
    if (ec) throw std::runtime_error("capture_rename_failed");
    char captured[256];
    std::snprintf(captured, sizeof captured, "OJ_IMAGE_SPJ/1 CAPTURED case=%s width=%u height=%u total_ms=%.3f\n",
        name.c_str(), image.width, image.height, milliseconds(start));
    emit(captured);
    return true;
}

// Per-problem comparison settings, written by the platform next to the reference images. Only the color
// tolerance can be changed and it is capped, so a damaged file can never switch the comparison off.
void load_settings(const char* reference_path) {
    namespace fs = std::filesystem;
    std::error_code ec;
    const fs::path directory = fs::weakly_canonical(fs::absolute(reference_path, ec), ec).parent_path();
    if (ec) return;
    const fs::path file = directory / settings_file_name;
    const auto status = fs::symlink_status(file, ec);
    if (ec || status.type() != fs::file_type::regular) return;
    std::ifstream in(file, std::ios::binary);
    if (!in) {
        g_settings_note = "unreadable";
        return;
    }
    const auto size = fs::file_size(file, ec);
    if (ec || size > 256) throw std::runtime_error("settings_invalid");
    const std::string key = "color_tolerance=";
    std::string line;
    while (std::getline(in, line)) {
        while (!line.empty() && (line.back() == char(13) || line.back() == ' ')) line.pop_back();
        if (line.empty() || line[0] == '#') continue;
        if (line.compare(0, key.size(), key) != 0) throw std::runtime_error("settings_invalid");
        const std::string value = line.substr(key.size());
        if (value.empty() || value.size() > 3) throw std::runtime_error("settings_invalid");
        int parsed = 0;
        for (const char c : value) {
            if (!std::isdigit(static_cast<unsigned char>(c))) throw std::runtime_error("settings_invalid");
            parsed = parsed * 10 + (c - '0');
        }
        if (parsed > settings_max_color_tolerance) throw std::runtime_error("settings_invalid");
        g_color_tolerance = parsed;
    }
}

int run(int argc, char** argv) {
    const auto start = Clock::now();
    set_limits();
    const bool preflight = argc == 3 && std::strcmp(argv[1], "--check-reference") == 0;
    if (!preflight && argc != 4) throw std::runtime_error("usage_spj_input_expected_actual");
    if (!preflight) {
        g_reference_path = argv[2];
        const std::string stem = std::filesystem::path(argv[1]).stem().string();
        if (valid_case_name(stem)) g_case_name = stem;
    }
    if (!preflight && try_capture(argv[1], argv[2], argv[3], start)) return 0;
    if (!preflight) load_settings(argv[2]);
    Image expected;
    try { expected = decode(unwrap_reference(read_regular_file(argv[2], max_reference_bytes))); }
    catch (const InvalidImage& error) { throw std::runtime_error(std::string("reference_") + error.what()); }
    if (preflight) {
        std::fprintf(stderr, "OJ_IMAGE_SPJ/1 REFERENCE_OK width=%u height=%u total_ms=%.3f\n",
            expected.width, expected.height, milliseconds(start));
        return 0;
    }
    Image actual;
    try { actual = decode(read_regular_file(argv[3], max_png_bytes)); }
    catch (const InvalidImage& error) {
        char rejected[256];
        std::snprintf(rejected, sizeof rejected, "OJ_IMAGE_SPJ/1 WA reason=output_%s total_ms=%.3f\n", error.what(), milliseconds(start));
        emit(rejected);
        return 1;
    }
    const double decode_ms = milliseconds(start);
    if (expected.width != actual.width || expected.height != actual.height) {
        char mismatch[256];
        std::snprintf(mismatch, sizeof mismatch,
            "OJ_IMAGE_SPJ/1 WA reason=dimensions_mismatch case=%s expected=%ux%u actual=%ux%u total_ms=%.3f\n",
            g_case_name.c_str(), expected.width, expected.height, actual.width, actual.height, milliseconds(start));
        emit(mismatch);
        write_side_file(".actual.png", read_text_file_for_side(argv[3]), false);
        return 1;
    }
    const auto comparison_start = Clock::now();
    const auto result = compare(expected, actual);
    const bool accepted = result.missing <= max_unmatched_pixels && result.extra <= max_unmatched_pixels;
    char verdict[768];
    std::snprintf(verdict, sizeof verdict,
        "OJ_IMAGE_SPJ/1 %s reason=%s case=%s width=%u height=%u unmatched_expected=%zu unmatched_actual=%zu "
        "diff_bbox=%d,%d,%d,%d max_delta=%d color_tolerance=%d spatial_radius=%d max_unmatched=%u decode_ms=%.3f compare_ms=%.3f total_ms=%.3f\n",
        accepted ? "AC" : "WA", accepted ? "within_tolerance" : "pixels_differ", g_case_name.c_str(),
        expected.width, expected.height, result.missing, result.extra,
        result.min_x, result.min_y, result.max_x, result.max_y, result.max_delta, g_color_tolerance, spatial_radius, max_unmatched_pixels,
        decode_ms, milliseconds(comparison_start), milliseconds(start));
    emit(verdict);
    if (accepted) write_side_file(".actual.png", std::string(), true);
    else write_side_file(".actual.png", read_text_file_for_side(argv[3]), false);
    return accepted ? 0 : 1;
}
}

int main(int argc, char** argv) {
    try { return image_spj::run(argc, argv); }
    catch (const std::bad_alloc&) {
        image_spj::emit("OJ_IMAGE_SPJ/1 CHECKER_ERROR reason=memory_limit\n");
    } catch (const std::exception& error) {
        image_spj::emit(std::string("OJ_IMAGE_SPJ/1 CHECKER_ERROR reason=") + error.what() + "\n");
    } catch (...) {
        image_spj::emit("OJ_IMAGE_SPJ/1 CHECKER_ERROR reason=unexpected_error\n");
    }
    // Stock HUSTOJ maps 3 to RE, not to a distinct infrastructure-error status.
    return 3;
}
