"use strict";
// Audio files are already tempo-adjusted by FFmpeg. Always play them at 1x so
// browser time-stretching doesn't process the same speech a second time.
globalThis.VmAudio = Object.freeze({
  errorMessage(error) {
    switch (error?.code) {
      case 2:
        return "Audio download failed or the private link expired. Press Replay to request a fresh link.";
      case 3:
        return "The browser could not decode this audio. Press Replay to retry, or try another message.";
      case 4:
        return "The audio file is unavailable or unsupported. Press Replay to retry.";
      default:
        return "Audio could not be loaded. Press Replay to retry. Check your connection and the server's PHP log if it keeps failing.";
    }
  },
  sourcePosition(audio, rate) {
    return Number.isFinite(audio.currentTime)
      ? Math.max(0, audio.currentTime * rate)
      : 0;
  },
  load(audio, url, sourcePosition = 0, rate = 1, timeoutMs = 20000) {
    return new Promise((resolve, reject) => {
      const cleanup = () => {
        clearTimeout(timer);
        audio.removeEventListener("loadedmetadata", ready);
        audio.removeEventListener("error", failed);
      };
      const failed = () => {
        cleanup();
        reject(new Error(globalThis.VmAudio.errorMessage(audio.error)));
      };
      const ready = () => {
        try {
          if (!Number.isFinite(audio.duration) || audio.duration <= 0) {
            throw new Error(
              "This message has no playable duration. Try Replay or another message.",
            );
          }
          audio.defaultPlaybackRate = 1;
          audio.playbackRate = 1;
          audio.preservesPitch = true;
          audio.currentTime = Math.min(
            Math.max(0, sourcePosition / rate),
            Math.max(0, audio.duration - 0.01),
          );
          cleanup();
          resolve();
        } catch (error) {
          cleanup();
          reject(error);
        }
      };
      const timer = setTimeout(() => {
        cleanup();
        reject(
          new Error(
            "Audio loading timed out. Press Replay to retry. Check media.php in the browser Network tab if this continues.",
          ),
        );
      }, timeoutMs);
      audio.addEventListener("loadedmetadata", ready);
      audio.addEventListener("error", failed);
      try {
        audio.defaultPlaybackRate = 1;
        audio.playbackRate = 1;
        audio.src = url;
        audio.load();
      } catch (error) {
        cleanup();
        reject(error);
      }
    });
  },
});
