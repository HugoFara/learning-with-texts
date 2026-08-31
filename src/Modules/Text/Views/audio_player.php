<?php

/**
 * Client-driven audio player for the reading screen.
 *
 * Markup-only counterpart of MediaService::renderHtml5AudioPlayer: it carries
 * NO per-text data (no <source src>, no data-audio-config blob). The Alpine
 * `audioPlayer` component fetches GET /texts/{id}/audio on init, sets the audio
 * source + offset + player settings, and reveals the player (x-show="hasAudio")
 * only when the text actually has audio. This keeps read_desktop.php free of
 * server-rendered audio data so the reader works in a bundled/offline client.
 *
 * The control markup mirrors the server-rendered player exactly (same classes,
 * bindings and icons) so styling and behavior are unchanged.
 *
 * PHP version 8.2
 *
 * @category Views
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.0
 */

declare(strict_types=1);

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\IconHelper;

?>
<div class="box py-2 px-4 mb-0" style="border-radius: 0;"
     x-data="audioPlayer" x-show="hasAudio" x-cloak>
  <div class="audio-player-container">
    <!-- Source + offset + settings are set by the component from the API -->
    <audio preload="auto"></audio>

    <div class="audio-player">
      <!-- Play/Pause/Stop controls -->
      <div class="audio-player-controls">
        <button type="button" class="button is-small"
                :class="isPlaying ? 'is-primary' : 'is-light'"
                @click="togglePlay" :title="isPlaying ? 'Pause' : 'Play'">
          <?php echo IconHelper::render('play', ['x-show' => '!isPlaying', 'size' => 16]); ?>
          <?php echo IconHelper::render('pause', ['x-show' => 'isPlaying', 'size' => 16]); ?>
        </button>
        <button type="button" class="button is-small is-light" @click="stop" title="Stop">
          <?php echo IconHelper::render('square', ['size' => 16]); ?>
        </button>
      </div>

      <!-- Progress section -->
      <div class="audio-player-progress">
        <div class="progress-bar-container" @click="seekFromEvent($event)" title="Click to seek">
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
        <button type="button" class="button is-small is-light"
                @click="toggleMute" :title="isMuted ? 'Unmute' : 'Mute'">
          <?php echo IconHelper::render('volume-2', ['x-show' => '!isMuted && volume > 0.5', 'size' => 16]); ?>
          <?php
            echo IconHelper::render(
                'volume-1',
                ['x-show' => '!isMuted && volume > 0 && volume <= 0.5', 'size' => 16]
            );
            ?>
          <?php echo IconHelper::render('volume-x', ['x-show' => 'isMuted || volume === 0', 'size' => 16]); ?>
        </button>
        <div class="volume-bar-container" @click="setVolumeFromEvent($event)" title="Adjust volume">
          <div class="volume-bar" :style="{ width: (isMuted ? 0 : volume * 100) + '%' }"></div>
        </div>
      </div>

      <!-- Skip controls -->
      <div class="audio-player-skip">
        <button type="button" class="button is-small is-light"
                @click="skipBackward" :title="'Skip back ' + skipSeconds + 's'">
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
              <template x-for="sec in skipOptions" :key="sec">
                <a :class="skipOptionClass(sec)"
                   @click="setSkipSeconds(sec)"
                   x-text="skipOptionLabel(sec)"></a>
              </template>
            </div>
          </div>
        </div>
        <button type="button" class="button is-small is-light"
                @click="skipForward" :title="'Skip forward ' + skipSeconds + 's'">
          <?php echo IconHelper::render('skip-forward', ['size' => 16]); ?>
        </button>
      </div>

      <!-- Speed controls -->
      <div class="audio-player-speed">
        <button type="button" class="button is-small is-light"
                @click="slower" title="Slower" :disabled="playbackRate <= 0.5">
          <?php echo IconHelper::render('minus', ['size' => 14]); ?>
        </button>
        <button type="button" class="button is-small"
                :class="playbackRate === 1.0 ? 'is-light' : 'is-warning'"
                @click="resetSpeed" title="Reset to 1x speed">
          <span x-text="playbackRateFormatted" class="is-size-7"></span>
        </button>
        <button type="button" class="button is-small is-light"
                @click="faster" title="Faster" :disabled="playbackRate >= 1.5">
          <?php echo IconHelper::render('plus', ['size' => 14]); ?>
        </button>
      </div>

      <!-- Repeat toggle -->
      <div class="audio-player-repeat">
        <button type="button" class="button is-small"
                :class="repeatMode ? 'is-info' : 'is-light'"
                @click="toggleRepeat" :title="repeatMode ? 'Repeat: ON' : 'Repeat: OFF'">
          <?php echo IconHelper::render('repeat', ['size' => 16]); ?>
        </button>
      </div>
    </div>
  </div>
</div>
