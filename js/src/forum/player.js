import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import { api } from './api';
import { t, clock, cover, discussionUrl, episodeLabel } from './util';

/*
 * The one player. A single <audio> element that lives outside every page, so
 * it keeps playing while members move around the forum (Flarum never reloads).
 *
 * Its dock is a Mithril root of its own. The clock and progress bar are
 * written straight to the DOM on timeupdate: a full redraw four times a second
 * would make every page work for a sound playing in the corner. Mithril
 * redraws only when something changes state (play, pause, a new episode, a
 * new chapter).
 */

const SPEEDS = [1, 1.25, 1.5, 1.75, 2, 0.8];
const SAVE_EVERY = 30;

const store = {
  get(key) {
    try {
      return JSON.parse(localStorage.getItem(`earshot:${key}`));
    } catch (e) {
      return null;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(`earshot:${key}`, JSON.stringify(value));
    } catch (e) {}
  },
};

class Player {
  constructor() {
    this.audio = null;
    this.episode = null;
    this.show = null;
    this.chapter = -1;
    this.lastSaved = 0;
    this.speed = store.get('speed') || 1;
    this.listeners = new Set();
  }

  /** Called on every timeupdate with (time, duration); returns an unsubscribe. */
  watch(fn) {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  }

  element() {
    if (this.audio) return this.audio;
    const a = (this.audio = new Audio());
    a.preload = 'metadata';
    a.addEventListener('timeupdate', () => this.tick());
    a.addEventListener('play', () => m.redraw());
    a.addEventListener('pause', () => {
      this.save();
      m.redraw();
    });
    a.addEventListener('ended', () => {
      this.save(true);
      m.redraw();
    });
    a.addEventListener('loadedmetadata', () => {
      if (this.episode && !this.episode.duration && isFinite(a.duration)) this.episode.duration = Math.round(a.duration);
      a.playbackRate = this.speed;
      this.tick();
    });
    mountDock();
    return a;
  }

  isCurrent(episode) {
    return !!this.episode && !!episode && this.episode.id === episode.id;
  }

  playing(episode) {
    return this.isCurrent(episode) && this.audio && !this.audio.paused;
  }

  /** Play an episode, from `at` seconds or where this listener stopped. */
  play(episode, show, at = null) {
    const a = this.element();
    if (this.isCurrent(episode)) {
      if (at !== null) a.currentTime = at;
      a.play();
      return;
    }
    this.save();
    this.episode = episode;
    this.show = show;
    this.chapter = -1;
    const local = store.get(`pos:${episode.id}`);
    const saved = episode.progress && !episode.progress.finished ? episode.progress.position : local || 0;
    a.src = episode.audio;
    a.currentTime = at !== null ? at : saved;
    a.playbackRate = this.speed;
    a.play().catch(() => m.redraw());
    this.session();
    app.earshot.player = { episode, stamp: () => clock(this.audio.currentTime) };
    m.redraw();
  }

  toggle(episode, show) {
    if (this.playing(episode)) this.audio.pause();
    else this.play(episode, show);
  }

  seek(seconds) {
    if (!this.audio) return;
    this.audio.currentTime = Math.max(0, Math.min(seconds, this.duration() || seconds));
    this.tick();
  }

  skip(delta) {
    if (this.audio) this.seek(this.audio.currentTime + delta);
  }

  cycleSpeed() {
    this.speed = SPEEDS[(SPEEDS.indexOf(this.speed) + 1) % SPEEDS.length];
    store.set('speed', this.speed);
    if (this.audio) this.audio.playbackRate = this.speed;
    m.redraw();
  }

  duration() {
    const d = this.audio && this.audio.duration;
    return isFinite(d) && d > 0 ? d : (this.episode && this.episode.duration) || 0;
  }

  time() {
    return this.audio ? this.audio.currentTime : 0;
  }

  close() {
    this.save();
    if (this.audio) {
      this.audio.pause();
      this.audio.removeAttribute('src');
      this.audio.load();
    }
    this.episode = this.show = null;
    app.earshot.player = null;
    m.redraw();
  }

  tick() {
    const time = this.time();
    const duration = this.duration();
    this.listeners.forEach((fn) => fn(time, duration));

    const chapters = (this.episode && this.episode.chapters) || [];
    let current = -1;
    chapters.forEach(([start], i) => time >= start && (current = i));
    if (current !== this.chapter) {
      this.chapter = current;
      m.redraw();
    }

    if (Math.abs(time - this.lastSaved) >= SAVE_EVERY) this.save();
  }

  /** Where this listener stopped: the browser always, the server for members. */
  save(finished = false) {
    const e = this.episode;
    if (!e || !this.audio) return;
    const position = Math.floor(this.audio.currentTime);
    this.lastSaved = this.audio.currentTime;
    const done = finished || (this.duration() > 0 && this.duration() - position < 30);
    e.progress = { position: done ? 0 : position, finished: done };
    store.set(`pos:${e.id}`, done ? 0 : position);
    if (app.session.user) {
      api.post('progress', { episode: e.id, position: e.progress.position, finished: done }).catch(() => {});
    }
  }

  /** Lock-screen and headset controls, where the browser offers them. */
  session() {
    if (!('mediaSession' in navigator) || !this.episode) return;
    const art = this.show && (this.show.coverSmall || this.show.cover);
    navigator.mediaSession.metadata = new MediaMetadata({
      title: this.episode.title,
      artist: this.show ? this.show.title : '',
      artwork: art ? [{ src: art, sizes: '512x512' }] : [],
    });
    const actions = {
      play: () => this.audio.play(),
      pause: () => this.audio.pause(),
      seekbackward: () => this.skip(-15),
      seekforward: () => this.skip(30),
      seekto: (d) => this.seek(d.seekTime),
    };
    Object.entries(actions).forEach(([name, fn]) => {
      try {
        navigator.mediaSession.setActionHandler(name, fn);
      } catch (e) {}
    });
  }
}

export const player = new Player();

/** The progress fill and clock, kept current without a redraw. */
export function liveProgress(vnode, fill, label, when = () => true) {
  const update = (time, duration) => {
    if (!when()) return;
    const f = vnode.dom.querySelector(fill);
    if (f) f.style.setProperty('--earshot-progress', duration ? Math.min(1, time / duration) : 0);
    const l = label && vnode.dom.querySelector(label);
    if (l) l.textContent = clock(time);
  };
  update(player.time(), player.duration());
  return player.watch(update);
}

/** Seek by a click or key on a bar: the fraction across it. */
export function seekFromBar(e) {
  const rect = e.currentTarget.getBoundingClientRect();
  player.seek(((e.clientX - rect.left) / rect.width) * player.duration());
}

export function barKeys(e) {
  if (e.key === 'ArrowLeft') player.skip(-15);
  else if (e.key === 'ArrowRight') player.skip(30);
  else return;
  e.preventDefault();
}

class Dock extends Component {
  oncreate(vnode) {
    super.oncreate(vnode);
    this.unwatch = liveProgress(vnode, '.EarshotDock-fill', '.EarshotDock-time');
  }

  onremove(vnode) {
    super.onremove(vnode);
    this.unwatch && this.unwatch();
  }

  view() {
    const e = player.episode;
    document.body.classList.toggle('Earshot-docked', !!e);
    if (!e) return <div />;
    const show = player.show || {};
    const playing = !player.audio.paused;
    const duration = player.duration();
    const label = episodeLabel(e);

    return (
      <div className="EarshotDock" role="region" aria-label={t('player.label')}>
        <Link className="EarshotDock-art" href={discussionUrl(e)} aria-hidden="true" tabindex="-1">
          {cover(show, 'EarshotCover', true)}
        </Link>
        <div className="EarshotDock-what">
          <Link className="EarshotDock-title" href={discussionUrl(e)}>
            {label ? `${label} · ` : ''}
            {e.title}
          </Link>
          <div className="EarshotDock-show">
            {show.title}
            {player.chapter >= 0 ? ` · ${e.chapters[player.chapter][1]}` : ''}
          </div>
        </div>
        <div className="EarshotDock-controls">
          <button type="button" className="EarshotDock-skip" onclick={() => player.skip(-15)} aria-label={t('player.back')}>
            -15
          </button>
          <button
            type="button"
            className="EarshotDock-play"
            onclick={() => player.toggle(e, show)}
            aria-label={playing ? t('player.pause') : t('player.play')}
          >
            <Icon name={playing ? 'fas fa-pause' : 'fas fa-play'} />
          </button>
          <button type="button" className="EarshotDock-skip" onclick={() => player.skip(30)} aria-label={t('player.forward')}>
            +30
          </button>
        </div>
        <div className="EarshotDock-bar">
          <span className="EarshotDock-time">{clock(player.time())}</span>
          <div
            className="EarshotDock-track"
            role="slider"
            tabindex="0"
            aria-label={t('player.seek')}
            aria-valuemin="0"
            aria-valuemax={Math.round(duration)}
            onclick={seekFromBar}
            onkeydown={barKeys}
          >
            <span className="EarshotDock-fill" />
            {(e.chapters || []).map(([start]) =>
              duration && start > 0 ? <span className="EarshotDock-tick" style={{ left: `${(start / duration) * 100}%` }} /> : null
            )}
          </div>
          <span className="EarshotDock-total">{duration ? clock(duration) : '--:--'}</span>
        </div>
        <button type="button" className="EarshotDock-speed" onclick={() => player.cycleSpeed()} aria-label={t('player.speed')}>
          {`${player.speed}×`}
        </button>
        <button type="button" className="EarshotDock-close" onclick={() => player.close()} aria-label={t('player.close')}>
          <Icon name="fas fa-times" />
        </button>
      </div>
    );
  }
}

let mounted = false;
function mountDock() {
  if (mounted) return;
  mounted = true;
  const el = document.createElement('div');
  el.className = 'EarshotDock-root';
  document.body.appendChild(el);
  m.mount(el, Dock);
}
