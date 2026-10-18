import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import ItemList from 'flarum/common/utils/ItemList';
import { api } from '../api';
import { player } from '../player';
import { t, ts, date, minutes, number, discussionUrl, episodeLabel } from '../util';
import ShowHero from './ShowHero';
import ShowModal from './ShowModal';
import EpisodeModal from './EpisodeModal';

/**
 * A show: its hero, then its episodes, newest first, a page at a time. Core's
 * PageStructure and IndexSidebar, so themes that style the index style this.
 */
export default class ShowPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--index';
    this.show = null;
    this.episodes = [];
    this.page = 0;
    this.more = false;
    this.loading = true;
    this.refreshing = false;
    this.load();
  }

  slug() {
    return m.route.param('slug');
  }

  load(page = 0) {
    this.loading = true;
    return api.get(`shows/${this.slug()}`, { page }).then(
      (r) => {
        this.show = r.show;
        this.episodes = page ? this.episodes.concat(r.episodes) : r.episodes;
        this.page = page;
        this.more = r.more;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        this.missing = true;
        m.redraw();
      }
    );
  }

  view() {
    if (this.show) app.setTitle(this.show.title);
    app.setTitleCount(0);

    return (
      <PageStructure className="IndexPage EarshotPage" hero={() => this.hero()} sidebar={() => <IndexSidebar />}>
        {this.content()}
      </PageStructure>
    );
  }

  hero() {
    if (!this.show) return null;
    const timed = this.episodes.filter((e) => e.duration);
    const average = timed.length ? timed.reduce((n, e) => n + e.duration, 0) / timed.length : 0;

    return <ShowHero show={this.show} latest={this.episodes[0]} averageDuration={average} controls={this.controls()} />;
  }

  controls() {
    const show = this.show;
    if (!show || !show.canEdit) return [];
    const out = [
      <Button
        className="Button EarshotHero-button"
        icon="fas fa-plus"
        onclick={() => app.modal.show(EpisodeModal, { show, onsave: () => this.load() })}
      >
        {t('show.publish')}
      </Button>,
      <Button
        className="Button EarshotHero-button"
        icon="fas fa-pen"
        onclick={() =>
          app.modal.show(ShowModal, {
            show,
            onsave: (saved) => (saved.slug !== show.slug ? m.route.set(app.route('earshot.show', { slug: saved.slug })) : this.load()),
          })
        }
      >
        {t('show.edit')}
      </Button>,
    ];
    if (show.follows) {
      out.push(
        <Button className="Button EarshotHero-button" icon="fas fa-sync" loading={this.refreshing} onclick={() => this.refresh()}>
          {t('show.refresh')}
        </Button>
      );
    }
    return out;
  }

  refresh() {
    this.refreshing = true;
    api.post(`shows/${this.show.id}/refresh`).then(
      (r) => {
        this.refreshing = false;
        app.alerts.show(
          { type: r.error ? 'error' : 'success' },
          r.error ? t('show.refresh_failed', { error: r.error }) : t('show.refreshed', { count: r.added })
        );
        this.load();
      },
      () => {
        this.refreshing = false;
        m.redraw();
      }
    );
  }

  content() {
    if (this.missing) return <Placeholder text={t('show.missing')} />;
    if (!this.show) return <LoadingIndicator />;

    return (
      <div className="EarshotEpisodes">
        {this.show.canEdit && this.show.fetchError ? (
          <div className="Alert Alert--error EarshotEpisodes-error">{t('show.fetch_error', { error: this.show.fetchError })}</div>
        ) : null}
        <div className="EarshotEpisodes-toolbar">
          <h2 className="EarshotEpisodes-heading">{t('show.episodes_heading', { count: number(this.show.episodeCount) })}</h2>
          {this.toolbarItems().toArray()}
        </div>
        {this.episodes.length ? (
          <ul className="EarshotEpisodes-list">{this.episodes.map((e) => this.row(e))}</ul>
        ) : this.loading ? null : (
          <Placeholder text={t('show.no_episodes')} />
        )}
        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="EarshotEpisodes-more">
            <Button className="Button" onclick={() => this.load(this.page + 1)}>
              {t('show.load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  /** Beside the episodes heading. Add-ons add here (search, a studio link). */
  toolbarItems() {
    return new ItemList();
  }

  row(e) {
    const show = this.show;
    const current = player.isCurrent(e);
    const playing = player.playing(e);
    const p = e.progress;
    const done = p && p.finished;
    const position = current ? player.time() : p ? p.position : 0;
    const fraction = done ? 1 : e.duration ? Math.min(1, position / e.duration) : 0;
    const label = episodeLabel(e);
    const left = !done && position > 0 && e.duration ? ts('show.left', { time: minutes(e.duration - position) }) : null;

    return (
      <li key={e.id} className={`EarshotEpisode ${current ? 'EarshotEpisode--current' : ''} ${done ? 'EarshotEpisode--done' : ''}`}>
        <button
          type="button"
          className="EarshotEpisode-play"
          onclick={() => player.toggle(e, show)}
          aria-label={playing ? t('player.pause') : t('player.play')}
        >
          <Icon name={playing ? 'fas fa-pause' : done ? 'fas fa-redo' : 'fas fa-play'} />
        </button>
        <div className="EarshotEpisode-main">
          <div className="EarshotEpisode-meta">
            {[label, date(e.publishedAt), minutes(e.duration), done ? ts('show.played') : left].filter(Boolean).join(' · ')}
          </div>
          <Link className="EarshotEpisode-title" href={discussionUrl(e)}>
            {e.title}
          </Link>
          {fraction > 0 ? (
            <div className="EarshotEpisode-progress" aria-hidden="true">
              <span style={{ width: `${fraction * 100}%` }} />
            </div>
          ) : null}
        </div>
        <Link className="EarshotEpisode-replies" href={discussionUrl(e)} aria-label={ts('show.replies_label', { count: e.replies })}>
          <Icon name="far fa-comment" /> {number(e.replies)}
        </Link>
      </li>
    );
  }
}
