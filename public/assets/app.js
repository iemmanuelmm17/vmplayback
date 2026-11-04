"use strict";
const app = document.querySelector("#app");
if (app) {
  const $ = (id) => document.getElementById(id);
  const audio = $("audio");
  let account = null,
    folder = "new",
    messages = [],
    index = -1,
    rate = 1,
    busy = false,
    selectionVersion = 0,
    trashEnabled = false;
  const current = () => messages[index];
  const status = (text, error = false) => {
    $("status").textContent = text;
    $("status").classList.toggle("error", error);
  };
  function controls() {
    for (const id of ["save", "trash", "forward", "replay", "next"])
      $(id).disabled = busy || !current();
    $("trash").disabled ||= folder === "trash" || !trashEnabled;
    $("save").disabled ||= folder === "save";
    $("forward").disabled ||= folder === "trash";
    $("next").disabled ||= index >= messages.length - 1;
    $("refresh").disabled = busy || !account;
    $("connect-button").disabled = busy;
    document.querySelectorAll("[data-folder]").forEach((button) => {
      button.disabled = busy;
    });
    document
      .querySelectorAll("#forward-form button, .message")
      .forEach((button) => {
        button.disabled = busy;
      });
    $("connect-form")
      .querySelectorAll("input")
      .forEach((input) => {
        input.disabled = busy;
      });
  }
  async function request(action, extra = {}) {
    const response = await fetch("api.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": app.dataset.csrf },
      body: new URLSearchParams({ ...account, folder, action, ...extra }),
    });
    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error("The server returned an unexpected response.");
    }
    if (!response.ok) throw new Error(data.error || "The request failed.");
    return data;
  }
  async function run(task) {
    if (busy) return;
    busy = true;
    controls();
    try {
      await task();
    } catch (error) {
      status(error.message, true);
    } finally {
      busy = false;
      controls();
    }
  }
  function renderList() {
    $("message-count").textContent = folder === "new" ? messages.length : "—";
    const list = $("message-list");
    list.replaceChildren();
    if (!messages.length) {
      const empty = document.createElement("div");
      empty.className = "empty";
      const title = document.createElement("h3");
      title.textContent = "You’re all caught up.";
      const text = document.createElement("p");
      text.textContent =
        "No messages in this folder. Try another folder or refresh.";
      empty.append(title, text);
      list.append(empty);
    }
    messages.forEach((message, n) => {
      const button = document.createElement("button");
      button.className = "message" + (n === index ? " selected" : "");
      button.disabled = busy;
      button.setAttribute("aria-pressed", String(n === index));
      const avatar = document.createElement("span");
      avatar.className = "list-avatar";
      avatar.textContent = (message.name || "?").slice(0, 1).toUpperCase();
      const info = document.createElement("span");
      info.className = "message-info";
      const name = document.createElement("strong");
      name.textContent = message.name || message.number || "Unknown caller";
      const number = document.createElement("span");
      number.textContent = message.number;
      const date = document.createElement("small");
      date.textContent = formatDate(message.date);
      const length = document.createElement("small");
      length.className = "duration";
      length.textContent = formatDuration(message.duration);
      info.append(name, number, date);
      button.append(avatar, info, length);
      button.addEventListener("click", () => run(() => select(n)));
      list.append(button);
    });
  }
  function formatDuration(seconds) {
    return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, "0")}`;
  }
  function formatDate(value) {
    const d = new Date(value);
    return Number.isNaN(d.getTime())
      ? value
      : d.toLocaleString(undefined, {
          dateStyle: "medium",
          timeStyle: "short",
        });
  }
  function stopAudio() {
    selectionVersion++;
    audio.pause();
    audio.removeAttribute("src");
    audio.load();
  }
  async function select(n) {
    stopAudio();
    index = n;
    const version = selectionVersion;
    renderList();
    controls();
    const m = current();
    $("caller-name").textContent = m?.name || "No message selected";
    $("caller-number").textContent =
      m?.number || "Choose a folder or refresh your mailbox.";
    $("message-date").textContent = m ? formatDate(m.date) : "";
    $("caller-avatar").textContent = (m?.name || "♪").slice(0, 1).toUpperCase();
    $("transcript-block").hidden = !m?.transcript;
    $("transcript").textContent = m?.transcript || "";
    $("folder-tag").textContent = {
      new: "NEW MESSAGES",
      save: "SAVED MESSAGES",
      trash: "TRASH",
    }[folder];
    if (!m) return;
    status("Preparing private audio…");
    const data = await request("audio", { filename: m.filename });
    if (version !== selectionVersion) return;
    audio.src = data.url;
    audio.playbackRate = rate;
    audio.preservesPitch = true;
    status(`Message ${index + 1} of ${messages.length}. Ready to listen.`);
  }
  async function load(preferredIndex = 0) {
    stopAudio();
    index = -1;
    messages = [];
    renderList();
    controls();
    status("Loading your voicemail…");
    const data = await request("list");
    messages = data.messages;
    trashEnabled = data.trash_enabled;
    await select(
      messages.length ? Math.min(preferredIndex, messages.length - 1) : -1,
    );
    if (!messages.length) status("No messages in this folder.");
    // After the operation ends, list buttons need their busy state cleared too.
  }
  $("connect-form").addEventListener("submit", (event) => {
    event.preventDefault();
    run(async () => {
      account = {
        AccountDomain: $("domain").value.trim(),
        AccountUser: $("extension").value.trim(),
      };
      await load();
    });
  });
  $("refresh").addEventListener("click", () => run(() => load(index)));
  document.querySelectorAll("[data-folder]").forEach((button) =>
    button.addEventListener("click", () =>
      run(async () => {
        folder = button.dataset.folder;
        document.querySelectorAll("[data-folder]").forEach((b) => {
          b.classList.toggle("active", b === button);
          b.setAttribute("aria-pressed", String(b === button));
        });
        if (account) await load();
      }),
    ),
  );
  function changeRate(value) {
    rate = Math.max(0.5, Math.min(2, value));
    audio.playbackRate = rate;
    audio.preservesPitch = true;
    $("speed-value").textContent = rate.toFixed(2) + "×";
  }
  $("slower").addEventListener("click", () => changeRate(rate - 0.25));
  $("faster").addEventListener("click", () => changeRate(rate + 0.25));
  $("normal-speed").addEventListener("click", () => changeRate(1));
  $("next").addEventListener("click", () => run(() => select(index + 1)));
  $("replay").addEventListener("click", () => {
    audio.currentTime = 0;
    audio.play().catch(() => status("Press play to listen."));
  });
  for (const action of ["save", "trash"])
    $(action).addEventListener("click", () =>
      run(async () => {
        if (
          action === "trash" &&
          !window.confirm(
            "Move this voicemail to trash? It can be restored from the Trash folder.",
          )
        )
          return;
        const oldIndex = index;
        await request(action, { filename: current().filename });
        await load(oldIndex);
        status(
          action === "save" ? "Message saved." : "Message moved to trash.",
        );
      }),
    );
  $("forward").addEventListener("click", () => {
    audio.pause();
    $("forward-error").textContent = "";
    $("destination").value = "";
    $("forward-dialog").showModal();
    $("destination").focus();
  });
  $("cancel-forward").addEventListener("click", () =>
    $("forward-dialog").close(),
  );
  $("forward-dialog").addEventListener("cancel", (event) => {
    if (busy) event.preventDefault();
  });
  $("forward-form").addEventListener("submit", (event) => {
    event.preventDefault();
    run(async () => {
      try {
        await request("forward", {
          filename: current().filename,
          destination: $("destination").value.trim(),
        });
      } catch (error) {
        $("forward-error").textContent = error.message;
        throw error;
      }
      $("forward-dialog").close();
      await load(index);
      status("Message forwarded. Mailbox refreshed.");
    });
  });
  audio.addEventListener("error", () => {
    if (audio.hasAttribute("src"))
      status(
        "Could not play audio. Refresh the mailbox to renew the private audio link.",
        true,
      );
  });
  document.addEventListener("keydown", (event) => {
    if (
      event.ctrlKey ||
      event.metaKey ||
      event.altKey ||
      /INPUT|TEXTAREA|SELECT|BUTTON/.test(event.target.tagName) ||
      $("forward-dialog").open
    )
      return;
    const map = {
      1: "save",
      2: "trash",
      4: "forward",
      5: "next",
      7: "slower",
      8: "replay",
      9: "faster",
    };
    if (map[event.key] && !$(map[event.key]).disabled) {
      event.preventDefault();
      $(map[event.key]).click();
    }
  });
  controls();
}
