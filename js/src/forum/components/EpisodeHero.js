import app from 'flarum/forum/app';
import DiscussionHero from 'flarum/forum/components/DiscussionHero';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import ItemList from 'flarum/common/utils/ItemList';
import listItems from 'flarum/common/helpers/listItems';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import { player, liveProgress, seekFromBar, barKeys } from '../player';
import { t, clock, date, minutes, episodeLabel, bars, cover } from '../util';
import { backdrop, meta, people } from './heroBits';
import { follow } from './actions';
import EpisodeModal from './EpisodeModal';

/**
 * The hero of an episode's discussion: Marquee's dark hero with the player
 * inside it, the chapters as cards, and the hosts.
 *
 * A DiscussionHero subclass, so the tags, badges and title are core's (and
 * every extension that adds to them still does). Add-ons extend
 * sectionItems() and actionItems().
 */
export default class EpisodeHero extends DiscussionHero {
  oncreate(vnode) {
    super.oncreate(vnode);
    this.unwatch = liveProgress(vnode, '.EarshotWave', '.EarshotWave-time', () => player.isCurrent(this.episode()));
  }

  onremove(vnode) {
    super.onremove(vnode);
    this.unwatch && this.unwatch();
  }

  episode() {
    return this.attrs.data.episode;
  }

  show() {
    return this.attrs.data.show;
  }

  view() {
    const discussion = this.attrs.discussion;
    const e = this.episode();
    const show = this.show();
    // The show's local 400 px cover; an episode's own art stays on its host.
    const art = e.image || show.coverSmall || show.cover;

    return (
      <header className="Hero DiscussionHero EarshotHero EarshotHero--episode" style={{ '--earshot-accent': show.color || undefined }}>
        {backdrop(art)}
        <div className="container EarshotHero-inner">
          <div className="EarshotHero-poster">
            {art ? <img className="EarshotCover" src={art} alt="" fetchpriority="high" /> : cover(show, 'EarshotCover', true)}
            {e.number ? <span className="EarshotHero-badge">{t('hero.ep_short', { episode: e.number })}</span> : null}
          </div>
          <div className="EarshotHero-body">
            <ul className="DiscussionHero-items">{listItems(this.pills(discussion).toArray())}</ul>
            <h1 className="DiscussionHero-title">{discussion.title()}</h1>
            {meta([date(e.publishedAt), minutes(e.duration), t('hero.replies', { count: e.replies }), e.explicit ? t('hero.explicit') : null])}
            {this.sectionItems().toArray()}
            <div className="EarshotHero-links">{this.actionItems().toArray()}</div>
          </div>
        </div>
      </header>
    );
  }

  pills(discussion) {
    const items = new ItemList();
    const tags = discussion.tags && discussion.tags();
    const badges = discussion.badges().toArray();
    const show = this.show();
    const label = episodeLabel(this.episode());

    // flarum/tags is optional: its helper is looked up, not imported.
    const tagsLabel = tags && tags.length && flarum.reg.get('flarum-tags', 'common/helpers/tagsLabel');
    if (tagsLabel) items.add('tags', tagsLabel(tags, { link: true }), 30);
    if (badges.length) items.add('badges', <ul className="DiscussionHero-badges badges">{listItems(badges)}</ul>, 20);
    items.add(
      'show',
      <Link className="EarshotHero-pill" href={app.route('earshot.show', { slug: show.slug })}>
        <Icon name="fas fa-chevron-left" /> {show.title}
      </Link>,
      10
    );
    if (label) items.add('number', <span className="EarshotHero-pill">{label}</span>, 0);

    return items;
  }

  sectionItems() {
    const items = new ItemList();
    const e = this.episode();
    const show = this.show();

    items.add('player', this.wave(e, show), 100);
    if (e.chapters && e.chapters.length) items.add('chapters', this.chapters(e, show), 80);
    items.add('people', people(t('hero.on_this_episode'), show.hosts, t('hero.host')), 60);

    return items;
  }

  wave(e, show) {
    const playing = player.playing(e);
    const current = player.isCurrent(e);
    const resume = !current && e.progress && !e.progress.finished && e.progress.position > 0 ? e.progress.position : 0;
    const shape = bars(e.id);

    return (
      <div className="EarshotWave" style={{ '--earshot-progress': current ? player.time() / (player.duration() || 1) : resume / (e.duration || 1) }}>
        <button
          type="button"
          className="EarshotWave-play"
          onclick={() => player.toggle(e, show)}
          aria-label={playing ? t('player.pause') : t('player.play')}
        >
          <Icon name={playing ? 'fas fa-pause' : 'fas fa-play'} />
        </button>
        <div className="EarshotWave-body">
          <div
            className="EarshotWave-bars"
            role="slider"
            tabindex="0"
            aria-label={t('player.seek')}
            onclick={(ev) => (current ? seekFromBar(ev) : player.play(e, show))}
            onkeydown={barKeys}
          >
            {['EarshotWave-layer', 'EarshotWave-layer EarshotWave-layer--played'].map((className) => (
              <span className={className} aria-hidden="true">
                {shape.map((h) => (
                  <span style={{ height: `${Math.round(h * 100)}%` }} />
                ))}
              </span>
            ))}
          </div>
          <div className="EarshotWave-times">
            <span className={current ? 'EarshotWave-time' : ''}>
              {current ? clock(player.time()) : resume ? t('hero.resume_at', { time: clock(resume) }) : t('hero.play_episode')}
            </span>
            {/* The audio's own length once loaded: hosts that insert ads run longer than the feed says. */}
            <span>{current && player.duration() ? clock(player.duration()) : e.duration ? clock(e.duration) : ''}</span>
          </div>
        </div>
      </div>
    );
  }

  chapters(e, show) {
    const current = player.isCurrent(e) ? player.chapter : -1;
    return (
      <div className="EarshotHero-chapters">
        <h2 className="EarshotHero-sectionTitle">{t('hero.chapters')}</h2>
        <ol>
          {e.chapters.map(([start, title], i) => (
            <li>
              <button type="button" className={i === current ? 'active' : ''} onclick={() => player.play(e, show, start)}>
                <span className="EarshotHero-chapterTime">{clock(start)}</span>
                <span className="EarshotHero-chapterTitle">{title}</span>
              </button>
            </li>
          ))}
        </ol>
      </div>
    );
  }

  actionItems() {
    const items = new ItemList();
    const e = this.episode();
    const show = this.show();
    const discussion = this.attrs.discussion;

    if (discussion.canReply()) {
      const at = player.isCurrent(e) ? clock(player.time()) : null;
      items.add(
        'reply',
        <Button className="Button Button--primary" icon="fas fa-reply" onclick={() => this.reply(at)}>
          {at ? t('hero.reply_at', { time: at }) : t('hero.reply')}
        </Button>,
        100
      );
    }
    if (show.canFollow !== false && app.session.user) {
      items.add('follow', follow(show), 90);
    }
    if (e.audio) {
      items.add(
        'download',
        <a className="Button EarshotHero-button" href={e.audio} download target="_blank" rel="noopener noreferrer">
          <Icon name="fas fa-download" />
          <span className="Button-label">{t('hero.download')}</span>
        </a>,
        80
      );
    }
    if (show.canEdit) {
      items.add(
        'edit',
        <Button
          className="Button EarshotHero-button"
          icon="fas fa-pen"
          onclick={() => app.modal.show(EpisodeModal, { show, episode: e, onsave: (saved) => Object.assign(e, saved) && m.redraw() })}
        >
          {t('hero.edit_episode')}
        </Button>,
        10
      );
    }

    return items;
  }

  /** Opens the reply composer, starting with the time the listener is at. */
  reply(at) {
    DiscussionControls.replyAction.call(this.attrs.discussion, true, false).then(() => {
      if (at && app.composer.editor) app.composer.editor.insertAtCursor(`${at} `);
    });
  }
}
