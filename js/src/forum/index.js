import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import DiscussionHero from 'flarum/forum/components/DiscussionHero';
import CommentPost from 'flarum/forum/components/CommentPost';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';

/*
 * Everything else loads on demand, in one chunk with its own stylesheet, so
 * the forum's every-page bundle carries only this file: the routes, one
 * sidebar link, the notification, and the hooks that fetch the chunk when a
 * page has a podcast on it. forum.css carries nothing.
 */
import stylesheet from './stylesheet';

const t = (key, params) => app.translator.trans(`ernestdefoe-earshot.forum.${key}`, params);

let styles;
let chunk;
let loaded = null;
const waiting = [];

/** The pages' code and styles, once, resolved when both have arrived so nothing paints unstyled. */
function load() {
  styles ??= new Promise((resolve) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `${app.forum.attribute('baseUrl')}/earshot/stylesheet?v=${stylesheet}`;
    link.onload = link.onerror = resolve;
    document.head.appendChild(link);
  });
  return (chunk ??= Promise.all([import('./earshot'), styles]).then(([mod]) => {
    loaded = mod;
    // Add-ons (Earshot Pro) extend the components once they exist.
    waiting.splice(0).forEach((fn) => fn(mod));
    return mod;
  }));
}

const page = (name) => () => load().then((mod) => ({ default: mod[name] }));

app.initializers.add('ernestdefoe-earshot', () => {
  /**
   * For add-ons: app.earshot.onLoad(fn) runs fn(module) when the chunk has
   * loaded (at once if it already has), without loading it on every page.
   */
  app.earshot = {
    load,
    onLoad(fn) {
      loaded ? fn(loaded) : waiting.push(fn);
    },
    // Set by the player while something is playing: { episode, time() }.
    player: null,
  };

  // Each route also exists in extend.php, so pasted links load.
  app.routes['earshot.index'] = { path: '/podcasts', component: page('PodcastsPage') };
  app.routes['earshot.show'] = { path: '/podcasts/:slug', component: page('ShowPage') };

  app.notificationComponents.earshotNewEpisode = class extends Notification {
    icon() {
      return 'fas fa-podcast';
    }
    href() {
      const discussion = this.attrs.notification.subject();
      return discussion ? app.route.discussion(discussion) : app.route('earshot.index');
    }
    content() {
      return t('notifications.new_episode', { show: this.attrs.notification.content()?.show || '' });
    }
    excerpt() {
      return this.attrs.notification.subject()?.title() || null;
    }
  };

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items) {
    items.add('earshotNewEpisode', { name: 'earshotNewEpisode', icon: 'fas fa-podcast', label: t('settings.notify_new_episode') });
  });

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canViewEarshot') || app.forum.attribute('earshotNav') === false) return;
    items.add('earshot', LinkButton.component({ href: app.route('earshot.index'), icon: 'fas fa-podcast' }, t('nav.link')), 7);
  });

  // An episode's discussion gets the episode hero, once the chunk is here;
  // until then (and for every other discussion) the normal hero shows.
  override(DiscussionHero.prototype, 'view', function (original) {
    const data = this.attrs.discussion.attribute('earshot');
    if (!data) return original();
    if (!loaded) {
      load().then(() => m.redraw());
      return original();
    }
    return loaded.EpisodeHero.component({ key: data.episode.id, data, discussion: this.attrs.discussion });
  });

  // "18:42" in a reply to an episode becomes a play-from-here chip.
  extend(CommentPost.prototype, ['oncreate', 'onupdate'], function () {
    const discussion = this.attrs.post.discussion();
    const data = discussion && discussion.attribute('earshot');
    if (!data) return;
    load().then((mod) => mod.linkTimestamps(this.element.querySelector('.Post-body'), data));
  });

  // The composer can drop in the player's current time while it plays.
  extend('flarum/common/components/TextEditor', 'toolbarItems', function (items) {
    const player = app.earshot.player;
    if (!player || !player.episode) return;
    items.add(
      'earshotTime',
      <button
        type="button"
        className="Button Button--icon Button--link hasIcon"
        title={t('composer.insert_time')}
        aria-label={t('composer.insert_time')}
        onclick={() => this.attrs.composer.editor.insertAtCursor(` ${player.stamp()} `)}
      >
        <i className="fas fa-podcast Button-icon" aria-hidden="true" />
      </button>,
      -50
    );
  });
});
