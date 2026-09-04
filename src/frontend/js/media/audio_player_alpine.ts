/**
 * Alpine.js Audio Player Component
 *
 * A modern, reactive audio player component using Alpine.js and Bulma CSS.
 * Replaces the legacy table-based media player with a cleaner interface.
 *
 * @license Unlicense
 * @since 3.0.0
 */

import Alpine from 'alpinejs';
import { saveSetting } from '@shared/utils/ajax_utilities';
import { apiGet } from '@shared/api/client';

/**
 * Audio player configuration from PHP
 */
export interface AudioPlayerConfig {
  containerId: string;
  mediaUrl: string;
  offset: number;
  repeatMode: boolean;
  skipSeconds: number;
  playbackRate: number;
}

/** Shape of GET /texts/{id}/audio (the shell-free config source). */
interface AudioApiResponse {
  uri: string;
  position: number;
  playerSettings: {
    repeatMode: boolean;
    skipSeconds: number;
    playbackRate: number;
  };
}

/** Normalized config applied to the player, regardless of source. */
interface NormalizedAudioConfig {
  mediaUrl: string;
  offset: number;
  repeatMode: boolean;
  skipSeconds: number;
  playbackRate: number;
}

/**
 * Audio player Alpine component interface
 */
export interface AudioPlayerData {
  // State
  isPlaying: boolean;
  isMuted: boolean;
  currentTime: number;
  duration: number;
  volume: number;
  playbackRate: number;
  repeatMode: boolean;
  skipSeconds: number;
  /** Skip intervals offered by the dropdown, in seconds. */
  readonly skipOptions: readonly number[];
  isLoaded: boolean;
  /** Whether the current text has audio; drives x-show on the player. */
  hasAudio: boolean;

  // Computed
  readonly progressPercent: number;
  readonly currentTimeFormatted: string;
  readonly durationFormatted: string;
  readonly playbackRateFormatted: string;

  // Audio element reference
  audio: HTMLAudioElement | null;

  // Lifecycle
  init(): void;
  destroy(): void;
  applyAudioConfig(config: NormalizedAudioConfig): void;
  loadAudioFromApi(): Promise<void>;

  // Playback controls
  play(): void;
  pause(): void;
  togglePlay(): void;
  stop(): void;

  // Seeking
  seekTo(percent: number): void;
  seekFromEvent(event: MouseEvent): void;
  skipBackward(): void;
  skipForward(): void;

  // Volume
  setVolume(vol: number): void;
  setVolumeFromEvent(event: MouseEvent): void;
  toggleMute(): void;

  // Speed
  setPlaybackRate(rate: number): void;
  slower(): void;
  faster(): void;
  resetSpeed(): void;

  // Repeat
  toggleRepeat(): void;

  // Skip time selection
  setSkipSeconds(seconds: number): void;
  skipOptionClass(seconds: number): string;
  skipOptionLabel(seconds: number): string;

  // Utilities
  formatTime(seconds: number): string;
}

/**
 * Playback rate options
 */
const PLAYBACK_RATES = [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 1.1, 1.2, 1.3, 1.4, 1.5];

/**
 * Skip time options in seconds
 */
const SKIP_OPTIONS = [1, 2, 3, 4, 5, 10, 15, 20, 25, 30];

/**
 * Create the audio player Alpine component data
 */
export function audioPlayerData(): AudioPlayerData {
  return {
    // State
    isPlaying: false,
    isMuted: false,
    currentTime: 0,
    duration: 0,
    volume: 1,
    playbackRate: 1.0,
    repeatMode: false,
    skipSeconds: 5,
    // The dropdown's choices. They lived in the view as a PHP loop over a
    // literal array, which is client-side UI configuration rendered by the
    // server for no reason -- the file's own header says it carries no data.
    skipOptions: [1, 2, 3, 4, 5, 10, 15, 20, 25, 30],
    isLoaded: false,
    hasAudio: false,

    // Audio element reference
    audio: null,

    // Computed properties
    get progressPercent(): number {
      if (this.duration <= 0) return 0;
      return (this.currentTime / this.duration) * 100;
    },

    get currentTimeFormatted(): string {
      return this.formatTime(this.currentTime);
    },

    get durationFormatted(): string {
      return this.formatTime(this.duration);
    },

    get playbackRateFormatted(): string {
      return this.playbackRate.toFixed(1) + 'x';
    },

    // Lifecycle
    init() {
      // Get container element - Alpine sets $el on the component instance
      const container = (this as unknown as { $el: HTMLElement }).$el;
      if (!container) {
        console.error('Audio player container not found');
        return;
      }

      // Get audio element from template
      this.audio = container.querySelector('audio') as HTMLAudioElement;
      if (!this.audio) {
        console.error('Audio element not found');
        return;
      }

      // Config has two sources. A server-rendered player embeds it inline
      // (data-audio-config); the shell-free reader ships markup only and the
      // player fetches GET /texts/{id}/audio instead. The inline path keeps the
      // legacy/improved-text views unchanged; the API path makes the reader work
      // in a bundled/offline client.
      const configEl = container.querySelector('[data-audio-config]');
      if (configEl) {
        try {
          const config = JSON.parse(configEl.textContent || '{}') as AudioPlayerConfig;
          this.applyAudioConfig(config);
          this.hasAudio = true;
        } catch (e) {
          console.error('Failed to parse audio config:', e);
        }
      } else {
        void this.loadAudioFromApi();
      }

      // Setup event listeners
      this.audio.addEventListener('loadedmetadata', () => {
        this.duration = this.audio!.duration;
        this.isLoaded = true;
      });

      this.audio.addEventListener('timeupdate', () => {
        this.currentTime = this.audio!.currentTime;
      });

      this.audio.addEventListener('play', () => {
        this.isPlaying = true;
        this.audio!.playbackRate = this.playbackRate;
      });

      this.audio.addEventListener('pause', () => {
        this.isPlaying = false;
      });

      this.audio.addEventListener('ended', () => {
        if (this.repeatMode) {
          this.audio!.currentTime = 0;
          this.audio!.play();
        } else {
          this.isPlaying = false;
        }
      });

      this.audio.addEventListener('volumechange', () => {
        this.volume = this.audio!.volume;
        this.isMuted = this.audio!.muted;
      });

      // Set initial volume
      this.volume = this.audio.volume;
      this.isMuted = this.audio.muted;
    },

    destroy() {
      // Cleanup if needed
    },

    /**
     * Apply a normalized config to the player: settings + source + start offset.
     * Shared by the inline (data-audio-config) and API (loadAudioFromApi) paths.
     */
    applyAudioConfig(config: NormalizedAudioConfig): void {
      this.repeatMode = config.repeatMode;
      this.skipSeconds = config.skipSeconds || 5;
      // playbackRate is stored as an integer x10 (10 = 1.0x) by both sources.
      this.playbackRate = (config.playbackRate || 10) / 10;

      if (config.mediaUrl && this.audio && !this.audio.src) {
        this.audio.src = config.mediaUrl;
      }

      if (config.offset > 0 && this.audio) {
        this.audio.addEventListener('loadedmetadata', () => {
          this.audio!.currentTime = config.offset;
        }, { once: true });
      }
    },

    /**
     * Fetch the audio config from GET /texts/{id}/audio for a shell-free reader.
     *
     * The text id comes from the reader config blob on the page. The player only
     * reveals itself (hasAudio) when the text actually has an audio URI; any
     * failure or absent audio leaves it hidden.
     */
    async loadAudioFromApi(): Promise<void> {
      const cfgEl = document.getElementById('text-reader-config');
      if (!cfgEl?.textContent) return;

      let textId: number;
      try {
        textId = (JSON.parse(cfgEl.textContent) as { textId?: number }).textId ?? 0;
      } catch {
        return;
      }
      if (textId <= 0) return;

      const res = await apiGet<AudioApiResponse>(`/texts/${textId}/audio`);
      const info = res.data;
      if (!info || !info.uri) return;

      this.applyAudioConfig({
        mediaUrl: info.uri,
        offset: info.position,
        repeatMode: info.playerSettings.repeatMode,
        skipSeconds: info.playerSettings.skipSeconds,
        playbackRate: info.playerSettings.playbackRate,
      });
      this.hasAudio = true;
    },

    // Playback controls
    play() {
      this.audio?.play();
    },

    pause() {
      this.audio?.pause();
    },

    togglePlay() {
      if (this.isPlaying) {
        this.pause();
      } else {
        this.play();
      }
    },

    stop() {
      if (this.audio) {
        this.audio.pause();
        this.audio.currentTime = 0;
      }
    },

    // Seeking
    seekTo(percent: number) {
      if (this.audio && this.duration > 0) {
        this.audio.currentTime = (percent / 100) * this.duration;
      }
    },

    seekFromEvent(event: MouseEvent) {
      const target = event.currentTarget as HTMLElement;
      const rect = target.getBoundingClientRect();
      const percent = ((event.clientX - rect.left) / rect.width) * 100;
      this.seekTo(Math.max(0, Math.min(100, percent)));
    },

    skipBackward() {
      if (this.audio) {
        const newTime = Math.max(0, this.audio.currentTime - this.skipSeconds);
        this.audio.currentTime = newTime;
      }
    },

    skipForward() {
      if (this.audio) {
        const newTime = Math.min(this.duration, this.audio.currentTime + this.skipSeconds);
        this.audio.currentTime = newTime;
      }
    },

    // Volume
    setVolume(vol: number) {
      if (this.audio) {
        this.audio.volume = Math.max(0, Math.min(1, vol));
      }
    },

    setVolumeFromEvent(event: MouseEvent) {
      const target = event.currentTarget as HTMLElement;
      const rect = target.getBoundingClientRect();
      const percent = (event.clientX - rect.left) / rect.width;
      this.setVolume(Math.max(0, Math.min(1, percent)));
    },

    toggleMute() {
      if (this.audio) {
        this.audio.muted = !this.audio.muted;
      }
    },

    // Speed
    setPlaybackRate(rate: number) {
      this.playbackRate = Math.max(0.5, Math.min(1.5, rate));
      if (this.audio) {
        this.audio.playbackRate = this.playbackRate;
      }
      // Save to server
      const rateValue = Math.round(this.playbackRate * 10);
      saveSetting('currentplaybackrate', String(rateValue));
    },

    slower() {
      const currentIndex = PLAYBACK_RATES.findIndex(r => Math.abs(r - this.playbackRate) < 0.05);
      if (currentIndex > 0) {
        this.setPlaybackRate(PLAYBACK_RATES[currentIndex - 1]);
      } else if (this.playbackRate > PLAYBACK_RATES[0]) {
        this.setPlaybackRate(PLAYBACK_RATES[0]);
      }
    },

    faster() {
      const currentIndex = PLAYBACK_RATES.findIndex(r => Math.abs(r - this.playbackRate) < 0.05);
      if (currentIndex < PLAYBACK_RATES.length - 1 && currentIndex >= 0) {
        this.setPlaybackRate(PLAYBACK_RATES[currentIndex + 1]);
      } else if (this.playbackRate < PLAYBACK_RATES[PLAYBACK_RATES.length - 1]) {
        // Find the next higher rate
        const nextRate = PLAYBACK_RATES.find(r => r > this.playbackRate);
        if (nextRate) {
          this.setPlaybackRate(nextRate);
        }
      }
    },

    resetSpeed() {
      this.setPlaybackRate(1.0);
    },

    // Repeat
    toggleRepeat() {
      this.repeatMode = !this.repeatMode;
      if (this.audio) {
        this.audio.loop = this.repeatMode;
      }
      saveSetting('currentplayerrepeatmode', this.repeatMode ? '1' : '0');
    },

    // Skip time
    setSkipSeconds(seconds: number) {
      this.skipSeconds = seconds;
      saveSetting('currentplayerseconds', String(seconds));
    },

    // The CSP build of Alpine cannot evaluate an object literal or a template
    // string in an attribute, so the two bindings the dropdown needs are
    // methods rather than inline expressions.
    skipOptionClass(seconds: number): string {
      return this.skipSeconds === seconds ? 'dropdown-item is-size-7 is-active' : 'dropdown-item is-size-7';
    },

    skipOptionLabel(seconds: number): string {
      return `${seconds}s`;
    },

    // Utilities
    formatTime(seconds: number): string {
      if (isNaN(seconds) || !isFinite(seconds)) return '0:00';
      const mins = Math.floor(seconds / 60);
      const secs = Math.floor(seconds % 60);
      return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
  };
}

/**
 * Export for legacy compatibility
 */
export { PLAYBACK_RATES, SKIP_OPTIONS };

/**
 * Initialize the audio player Alpine component
 */
export function initAudioPlayerAlpine(): void {
  Alpine.data('audioPlayer', audioPlayerData);
}

// Register component immediately
initAudioPlayerAlpine();

// Expose for global access
declare global {
  interface Window {
    audioPlayerData: typeof audioPlayerData;
  }
}

window.audioPlayerData = audioPlayerData;
