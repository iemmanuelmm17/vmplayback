import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

const context = vm.createContext({ setTimeout, clearTimeout });
vm.runInContext(
  readFileSync(new URL("../public/assets/audio.js", import.meta.url), "utf8"),
  context,
);
const player = context.VmAudio;
class FakeAudio extends EventTarget {
  duration = 10;
  currentTime = 0;
  playbackRate = 1.75;
  defaultPlaybackRate = 1.75;
  error = null;
  mode = "ready";
  listeners = 0;
  addEventListener(...args) {
    this.listeners++;
    super.addEventListener(...args);
  }
  removeEventListener(...args) {
    this.listeners--;
    super.removeEventListener(...args);
  }
  load() {
    if (this.mode === "timeout") return;
    queueMicrotask(() =>
      this.dispatchEvent(
        new Event(this.mode === "error" ? "error" : "loadedmetadata"),
      ),
    );
  }
}
let checks = 0;
const check = (value) => {
  assert.ok(value);
  checks++;
};
const audio = new FakeAudio();
await player.load(audio, "/media.php?private-test-link", 6, 1.5);
check(audio.src === "/media.php?private-test-link");
check(audio.currentTime === 4); // source-time 6s / rendered rate 1.5x
check(audio.playbackRate === 1 && audio.defaultPlaybackRate === 1); // no double stretching
check(audio.listeners === 0);
check(player.sourcePosition(audio, 1.5) === 6);
await player.load(audio, "/new-link", 6, 0.75);
check(audio.currentTime === 8 && audio.playbackRate === 1);
await player.load(audio, "/new-link", 100, 2);
check(audio.currentTime < audio.duration && audio.currentTime > 9.9);
audio.currentTime = NaN;
check(player.sourcePosition(audio, 1) === 0);
for (const code of [2, 3, 4, 0]) {
  const failed = new FakeAudio();
  failed.mode = "error";
  failed.error = { code };
  await assert.rejects(player.load(failed, "/bad-link"), /Replay/);
  checks++;
  check(failed.listeners === 0);
}
const stalled = new FakeAudio();
stalled.mode = "timeout";
await assert.rejects(player.load(stalled, "/stalled", 0, 1, 5), /timed out/);
checks++;
check(stalled.listeners === 0);
const empty = new FakeAudio();
empty.duration = 0;
await assert.rejects(player.load(empty, "/empty"), /no playable duration/);
checks++;
check(empty.listeners === 0);
console.log(
  `PASS: ${checks} browser audio loading, retry errors, seeking and single-stretch checks.`,
);
