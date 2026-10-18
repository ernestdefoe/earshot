import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import extractText from 'flarum/common/utils/extractText';
import { api } from '../api';
import { t, date, number, cover, avatar } from '../util';
import ShowModal from './ShowModal';

/** Every show, the most recently updated first. */
export default class PodcastsPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--index';
    this.shows = null;
    this.load();
  }

  load() {
    return api.get('shows').then((r) => {
      this.shows = r.shows;
      this.canCreate = r.canCreate;
      m.redraw();
    });
  }

  view() {
    app.setTitle(extractText(t('index.title')));
    app.setTitleCount(0);

    return (
      <PageStructure className="IndexPage EarshotPage" hero={() => this.hero()} sidebar={() => <IndexSidebar />}>
        {this.content()}
      </PageStructure>
    );
  }

  hero() {
    return (
      <header className="Hero EarshotHero EarshotHero--index">
        <div className="EarshotHero-backdrop" />
        <div className="container EarshotHero-index">
          <div>
            <h1 className="DiscussionHero-title">{t('index.title')}</h1>
            <p className="EarshotHero-tagline">{t('index.subtitle')}</p>
          </div>
          {this.canCreate ? (
            <Button
              className="Button Button--primary"
              icon="fas fa-plus"
              onclick={() => app.modal.show(ShowModal, { onsave: (show) => m.route.set(app.route('earshot.show', { slug: show.slug })) })}
            >
              {t('index.new_show')}
            </Button>
          ) : null}
        </div>
      </header>
    );
  }

  content() {
    if (!this.shows) return <LoadingIndicator />;
    if (!this.shows.length) return <Placeholder text={t('index.empty')} />;

    return (
      <ul className="EarshotShows">
        {this.shows.map((show) => (
          <li key={show.id}>
            <Link
              className="EarshotShowCard"
              href={app.route('earshot.show', { slug: show.slug })}
              style={{ '--earshot-accent': show.color || undefined }}
            >
              {cover(show, 'EarshotShowCard-cover EarshotCover', true)}
              <div className="EarshotShowCard-body">
                <h2 className="EarshotShowCard-title">{show.title}</h2>
                {show.tagline ? <p className="EarshotShowCard-tagline">{show.tagline}</p> : null}
                <div className="EarshotShowCard-meta">
                  <span className="EarshotShowCard-hosts">{show.hosts.slice(0, 3).map((u) => avatar(u, 'Avatar--mini'))}</span>
                  {[
                    t('hero.episodes', { count: show.episodeCount }),
                    show.lastEpisodeAt ? t('index.latest', { date: date(show.lastEpisodeAt) }) : null,
                  ]
                    .filter(Boolean)
                    .map((part) => (
                      <span>{part}</span>
                    ))}
                </div>
              </div>
            </Link>
          </li>
        ))}
      </ul>
    );
  }
}
