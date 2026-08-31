<?php

/**
 * Media Service - Business logic for media file handling and player generation
 *
 * This service handles:
 * - Media file discovery (audio/video in media folder)
 * - HTML media player generation (audio/video)
 * - Support for local files and streaming platforms (YouTube, Vimeo, Dailymotion, Bilibili, NicoNico, PeerTube)
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\Services;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * Service class for media file handling and player generation.
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class MediaService
{
    /**
     * Supported file formats for import.
     *
     * @var string[]
     */
    private const SUPPORTED_FORMATS = [
        // Audio/video
        'mp3', 'mp4', 'ogg', 'wav', 'webm', 'm4a', 'mkv', 'flac',
        // Text/subtitles
        'txt', 'srt', 'vtt', 'epub'
    ];

    /**
     * Audio-only formats.
     *
     * @var string[]
     */
    /**
     * Audio file extensions (without the leading dot).
     *
     * Note: matched via pathinfo() so case and 3-vs-4-letter suffix
     * differences (mp3/flac/aac) work — the old substr(-4) compare
     * mis-classified .mp3 as not-audio and dropped .opus/.aac entirely.
     */
    private const AUDIO_FORMATS = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'opus', 'aac'];

    // =========================================================================
    // Media File Discovery
    // =========================================================================

    /**
     * Return the list of media files found in folder, recursively.
     *
     * @param string $dir Directory to search into.
     *
     * @return array{paths: string[], folders: string[]}
     */
    public function searchMediaPaths(string $dir): array
    {
        $isWindows = str_starts_with(strtoupper(PHP_OS), "WIN");
        $paths = [
            "paths" => [$dir],
            "folders" => [$dir]
        ];

        if (!is_dir($dir)) {
            return $paths;
        }

        $mediadir = scandir($dir);
        if ($mediadir === false) {
            return $paths;
        }

        // For each item in directory - add files to paths
        foreach ($mediadir as $path) {
            if (str_starts_with($path, ".") || is_dir($dir . '/' . $path)) {
                continue;
            }
            // Encode path for Windows
            if ($isWindows) {
                $result = mb_convert_encoding($path, 'UTF-8', 'Windows-1252');
                $encoded = is_string($result) ? $result : $path;
            } else {
                $encoded = $path;
            }
            $ex = strtolower(pathinfo($encoded, PATHINFO_EXTENSION));
            if (in_array($ex, self::SUPPORTED_FORMATS)) {
                $paths["paths"][] = $dir . '/' . $encoded;
            }
        }

        // Do the folder in a second time to get a better ordering
        foreach ($mediadir as $path) {
            if (str_starts_with($path, ".") || !is_dir($dir . '/' . $path)) {
                continue;
            }
            // For each folder, recursive search
            $subfolderPaths = $this->searchMediaPaths($dir . '/' . $path);
            $paths["folders"] = array_merge($paths["folders"], $subfolderPaths["folders"]);
            $paths["paths"] = array_merge($paths["paths"], $subfolderPaths["paths"]);
        }

        return $paths;
    }

    /**
     * Return the paths for all media files.
     *
     * @return array{base_path: string, paths?: string[], folders?: string[], error?: string}
     */
    public function getMediaPaths(): array
    {
        $cwd = getcwd();
        $answer = [
            "base_path" => $cwd !== false ? basename($cwd) : ''
        ];

        if (!file_exists('media')) {
            $answer["error"] = "does_not_exist";
        } elseif (!is_dir('media')) {
            $answer["error"] = "not_a_directory";
        } else {
            $paths = $this->searchMediaPaths('media');
            $answer["paths"] = $paths["paths"];
            $answer["folders"] = $paths["folders"];
        }

        return $answer;
    }

    /**
     * Get the different options to display as acceptable media files.
     *
     * @param string $dir Directory containing files
     *
     * @return string HTML-formatted OPTION tags
     */
    public function getMediaPathOptions(string $dir): string
    {
        $r = "";
        $options = $this->searchMediaPaths($dir);
        foreach ($options["paths"] as $op) {
            if (in_array($op, $options["folders"])) {
                $escapedOp = htmlspecialchars($op, ENT_QUOTES, 'UTF-8');
                $r .= '<option disabled="disabled">-- Directory: ' . $escapedOp . '--</option>';
            } else {
                $escapedOp = htmlspecialchars($op, ENT_QUOTES, 'UTF-8');
                $r .= '<option value="' . $escapedOp . '">' . $escapedOp . '</option>';
            }
        }
        return $r;
    }

    /**
     * Generate HTML for media path selection UI.
     *
     * @param string $fieldName HTML field name for media string in form.
     *                          Will be used as this.form.[$fieldName] in JS.
     *
     * @return string HTML-formatted string for media selection
     */
    public function getMediaPathSelector(string $fieldName): string
    {
        $media = $this->getMediaPaths();
        $mediaJson = json_encode($media);
        $r = '<p>
            YouTube, Dailymotion, Vimeo, Bilibili, NicoNico, PeerTube, or choose a file in "../'
            . $media["base_path"] . '/media":
        </p>
        <p id="mediaSelectErrorMessage"></p>
        ' .
        IconHelper::render('loader-2', ['id' => 'mediaSelectLoadingImg', 'alt' => 'Loading...', 'class' => 'icon-spin'])
        . '
        <select name="Dir" data-action="media-dir-select"
        data-target-field="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '">
        </select>
        <span class="click" data-action="refresh-media-select">
            ' .
            IconHelper::render('refresh-cw', ['title' => 'Refresh Media Selection', 'alt' => 'Refresh Media Selection'])
            . '
            Refresh
        </span>
        <script type="application/json" data-lwt-media-select-config>' .
            ($mediaJson !== false ? $mediaJson : '{}') . '</script>';
        return $r;
    }

    // =========================================================================
    // Media Player Generation
    // =========================================================================

    /**
     * Get HTML for a media player, audio or video.
     *
     * @param string $path   URL or local file path
     * @param int    $offset Offset from the beginning of the video
     *
     * @return string HTML string for the media player, or empty string if no path
     */
    public function getMediaPlayerHtml(string $path, int $offset = 0): string
    {
        if ($path === '') {
            return '';
        }
        ob_start();
        $this->renderMediaPlayer($path, $offset);
        $result = ob_get_clean();
        return $result !== false ? $result : '';
    }

    /**
     * Create an HTML media player, audio or video.
     *
     * @param string $path   URL or local file path
     * @param int    $offset Offset from the beginning of the video
     *
     * @return void
     */
    public function renderMediaPlayer(string $path, int $offset = 0): void
    {
        if ($path === '') {
            return;
        }

        // pathinfo handles both .mp3 (3-letter) and .flac/.opus (4-letter);
        // strip query strings and case-fold first so "song.MP3?token=x"
        // still classifies as audio.
        $parsed = parse_url($path);
        $pathOnly = is_array($parsed) ? ($parsed['path'] ?? $path) : $path;
        $extension = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
        if (in_array($extension, self::AUDIO_FORMATS, true)) {
            $this->renderAudioPlayer($path, $offset);
        } else {
            $this->renderVideoPlayer($path, $offset);
        }
    }

    /**
     * Create an embed video player.
     *
     * @param string $path   URL or local file path
     * @param int    $offset Offset from the beginning of the video
     *
     * @return void
     */
    public function renderVideoPlayer(string $path, int $offset = 0): void
    {
        $online = false;
        $url = null;

        // Check for YouTube (youtube.com/watch?v=)
        if (
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([\w-]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://www.youtube.com/embed/" . $matches[1] . "?t=" . $offset;
            $online = true;
        } elseif (
            // Check for YouTube short URL (youtu.be/)
            preg_match(
                "/(?:https?:\/\/)?youtu\.be\/([\w-]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://www.youtube.com/embed/" . $matches[1] . "?t=" . $offset;
            $online = true;
        } elseif (
            // Check for YouTube Shorts
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([\w-]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://www.youtube.com/embed/" . $matches[1] . "?t=" . $offset;
            $online = true;
        } elseif (
            // Check for YouTube embed URL
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?youtube\.com\/embed\/([\w-]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://www.youtube.com/embed/" . $matches[1] . "?t=" . $offset;
            $online = true;
        } elseif (
            // Check for Dailymotion short URL (dai.ly/)
            preg_match(
                "/(?:https?:\/\/)?dai\.ly\/([^\?\/#]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://www.dailymotion.com/embed/video/" . $matches[1];
            $online = true;
        } elseif (
            // Check for Dailymotion full URL (dailymotion.com/video/)
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?dailymotion\.com\/video\/([^\?\/#]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://www.dailymotion.com/embed/video/" . $matches[1];
            $online = true;
        } elseif (
            // Check for Vimeo
            preg_match(
                "/(?:https:\/\/)?vimeo\.com\/(\d+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://player.vimeo.com/video/" . $matches[1] . "#t=" . $offset . "s";
            $online = true;
        } elseif (
            // Check for Bilibili (BV format)
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?bilibili\.com\/video\/(BV[\w]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://player.bilibili.com/player.html?bvid=" . $matches[1] . "&t=" . $offset;
            $online = true;
        } elseif (
            // Check for Bilibili (av format)
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?bilibili\.com\/video\/av(\d+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://player.bilibili.com/player.html?aid=" . $matches[1] . "&t=" . $offset;
            $online = true;
        } elseif (
            // Check for NicoNico (nicovideo.jp)
            preg_match(
                "/(?:https?:\/\/)?(?:www\.)?nicovideo\.jp\/watch\/([a-z]{2}\d+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://embed.nicovideo.jp/watch/" . $matches[1] . "?from=" . $offset;
            $online = true;
        } elseif (
            // Check for NicoNico short URL (nico.ms)
            preg_match(
                "/(?:https?:\/\/)?nico\.ms\/([a-z]{2}\d+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://embed.nicovideo.jp/watch/" . $matches[1] . "?from=" . $offset;
            $online = true;
        } elseif (
            // Check for PeerTube (federated - matches /videos/watch/ or /w/ pattern)
            preg_match(
                "/https?:\/\/([^\/]+)\/(?:videos\/watch|w)\/([a-zA-Z0-9-]+)/iu",
                $path,
                $matches
            )
        ) {
            $url = "https://" . $matches[1] . "/videos/embed/" . $matches[2] . "?start=" . $offset . "s";
            $online = true;
        }

        if ($online && $url !== null) {
            $this->renderOnlineVideoPlayer($url);
        } else {
            $this->renderLocalVideoPlayer($path);
        }
    }

    /**
     * Render an online video player in an iframe.
     *
     * @param string $url Video embed URL
     *
     * @return void
     */
    private function renderOnlineVideoPlayer(string $url): void
    {
        // TxAudioURI/TxSourceURI come from the user-supplied edit form
        // and are reached here unescaped. AudioUriValidator filters
        // schemes on save, but anything that survives still needs to
        // be HTML-escaped when echoed into an attribute, otherwise a
        // crafted URL with embedded quotes could break out and inject
        // markup or script in the reader view.
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        ?>
<iframe class="lwt-video-iframe"
src="<?= $safeUrl ?>"
title="Video player"
frameborder="0"
allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
allowfullscreen type="text/html">
</iframe>
        <?php
    }

    /**
     * Render a local video player.
     *
     * @param string $path Local file path
     *
     * @return void
     */
    private function renderLocalVideoPlayer(string $path): void
    {
        $type = "video/" . pathinfo($path, PATHINFO_EXTENSION);
        $title = pathinfo($path, PATHINFO_FILENAME);
        // Same XSS concern as renderOnlineVideoPlayer: escape every
        // user-controlled field that lands in an attribute.
        $safePath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
        $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        ?>
<video class="lwt-local-video" preload="auto" controls title="<?= $safeTitle ?>">
    <source src="<?= $safePath ?>" type="<?= $safeType ?>">
    <p>Your browser does not support video tags.</p>
</video>
        <?php
    }

    /**
     * Create an HTML audio player.
     *
     * @param string $audio  Audio URL
     * @param int    $offset Offset from the beginning of the audio
     *
     * @return void
     */
    public function renderAudioPlayer(string $audio, int $offset = 0): void
    {
        if ($audio === '') {
            return;
        }
        $audio = trim($audio);
        $repeatMode = (bool) Settings::getZeroOrOne('currentplayerrepeatmode', 0);
        $currentplayerseconds = Settings::get('currentplayerseconds');
        if ($currentplayerseconds === '') {
            $currentplayerseconds = 5;
        }
        $currentplaybackrate = Settings::get('currentplaybackrate');
        if ($currentplaybackrate === '') {
            $currentplaybackrate = 10;
        }

        $this->renderHtml5AudioPlayer(
            $audio,
            $offset,
            $repeatMode,
            (int) $currentplayerseconds,
            (int) $currentplaybackrate
        );
    }

    /**
     * Create an HTML5 native audio player (Vite mode).
     *
     * @param string $audio               Audio URL
     * @param int    $offset              Offset from the beginning
     * @param bool   $repeatMode          Whether to repeat
     * @param int    $currentplayerseconds Seconds to skip
     * @param int    $currentplaybackrate  Playback rate (10 = 1.0x)
     *
     * @return void
     */
    public function renderHtml5AudioPlayer(
        string $audio,
        int $offset,
        bool $repeatMode,
        int $currentplayerseconds,
        int $currentplaybackrate
    ): void {
        $config = [
            'containerId' => 'lwt-audio-player',
            'mediaUrl' => StringUtils::encodeURI($audio),
            'offset' => $offset,
            'repeatMode' => $repeatMode,
            'skipSeconds' => $currentplayerseconds,
            'playbackRate' => $currentplaybackrate
        ];
        $skipOptions = [1, 2, 3, 4, 5, 10, 15, 20, 25, 30];
        ?>
<div x-data="audioPlayer" class="audio-player-container" x-cloak>
    <!-- Hidden audio element -->
    <audio preload="auto">
        <source src="<?php echo htmlspecialchars($audio, ENT_QUOTES, 'UTF-8'); ?>">
        Your browser does not support the audio element.
    </audio>

    <!-- Config data -->
    <script type="application/json" data-audio-config><?php
    echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP);
    ?></script>

    <!-- Player UI -->
    <div class="audio-player">
        <!-- Play/Pause/Stop controls -->
        <div class="audio-player-controls">
            <button
                type="button"
                class="button is-small"
                :class="isPlaying ? 'is-primary' : 'is-light'"
                @click="togglePlay"
                :title="isPlaying ? 'Pause' : 'Play'"
            >
                <?php echo IconHelper::render('play', ['x-show' => '!isPlaying', 'size' => 16]); ?>
                <?php echo IconHelper::render('pause', ['x-show' => 'isPlaying', 'size' => 16]); ?>
            </button>
            <button
                type="button"
                class="button is-small is-light"
                @click="stop"
                title="Stop"
            >
                <?php echo IconHelper::render('square', ['size' => 16]); ?>
            </button>
        </div>

        <!-- Progress section -->
        <div class="audio-player-progress">
            <div
                class="progress-bar-container"
                @click="seekFromEvent($event)"
                title="Click to seek"
            >
                <div class="progress-bar" :style="{ width: progressPercent + '%' }"></div>
            </div>
            <div class="time-display">
                <span x-text="currentTimeFormatted">0:00</span>
                <span class="has-text-grey-light">/</span>
                <span x-text="durationFormatted">0:00</span>
            </div>
        </div>

        <!-- Volume control -->
        <div class="audio-player-volume">
            <button
                type="button"
                class="button is-small is-light"
                @click="toggleMute"
                :title="isMuted ? 'Unmute' : 'Mute'"
            >
                <?php echo IconHelper::render('volume-2', ['x-show' => '!isMuted && volume > 0.5', 'size' => 16]); ?>
                <?php
                echo IconHelper::render(
                    'volume-1',
                    ['x-show' => '!isMuted && volume > 0 && volume <= 0.5', 'size' => 16]
                );
                ?>
                <?php echo IconHelper::render('volume-x', ['x-show' => 'isMuted || volume === 0', 'size' => 16]); ?>
            </button>
            <div
                class="volume-bar-container"
                @click="setVolumeFromEvent($event)"
                title="Adjust volume"
            >
                <div class="volume-bar" :style="{ width: (isMuted ? 0 : volume * 100) + '%' }"></div>
            </div>
        </div>

        <!-- Skip controls -->
        <div class="audio-player-skip">
            <button
                type="button"
                class="button is-small is-light"
                @click="skipBackward"
                :title="'Skip back ' + skipSeconds + 's'"
            >
                <?php echo IconHelper::render('skip-back', ['size' => 16]); ?>
            </button>
            <div class="dropdown is-hoverable is-up">
                <div class="dropdown-trigger">
                    <button type="button" class="button is-small is-light" aria-haspopup="true">
                        <span x-text="skipSeconds + 's'" class="is-size-7"></span>
                    </button>
                </div>
                <div class="dropdown-menu" role="menu">
                    <div class="dropdown-content">
                        <?php foreach ($skipOptions as $sec) : ?>
                        <a
                            class="dropdown-item is-size-7"
                            :class="{ 'is-active': skipSeconds === <?php echo $sec; ?> }"
                            @click="setSkipSeconds(<?php echo $sec; ?>)"
                        ><?php echo $sec; ?>s</a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button
                type="button"
                class="button is-small is-light"
                @click="skipForward"
                :title="'Skip forward ' + skipSeconds + 's'"
            >
                <?php echo IconHelper::render('skip-forward', ['size' => 16]); ?>
            </button>
        </div>

        <!-- Speed controls -->
        <div class="audio-player-speed">
            <button
                type="button"
                class="button is-small is-light"
                @click="slower"
                title="Slower"
                :disabled="playbackRate <= 0.5"
            >
                <?php echo IconHelper::render('minus', ['size' => 14]); ?>
            </button>
            <button
                type="button"
                class="button is-small"
                :class="playbackRate === 1.0 ? 'is-light' : 'is-warning'"
                @click="resetSpeed"
                title="Reset to 1x speed"
            >
                <span x-text="playbackRateFormatted" class="is-size-7"></span>
            </button>
            <button
                type="button"
                class="button is-small is-light"
                @click="faster"
                title="Faster"
                :disabled="playbackRate >= 1.5"
            >
                <?php echo IconHelper::render('plus', ['size' => 14]); ?>
            </button>
        </div>

        <!-- Repeat toggle -->
        <div class="audio-player-repeat">
            <button
                type="button"
                class="button is-small"
                :class="repeatMode ? 'is-info' : 'is-light'"
                @click="toggleRepeat"
                :title="repeatMode ? 'Repeat: ON' : 'Repeat: OFF'"
            >
                <?php echo IconHelper::render('repeat', ['size' => 16]); ?>
            </button>
        </div>
    </div>
</div>
        <?php
    }
}
