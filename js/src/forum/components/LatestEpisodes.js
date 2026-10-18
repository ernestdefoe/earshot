import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { api } from '../api';
import { player } from '../player';
import { t, date, minutes, cover, discussionUrl } from '../util';

/*
 * One request for every widget on the page, kept for the visit: hosts remount
 * widgets on navigation, and state on the instance would refetch each time.
 */
const store = { data: null, loading: null };

function load() {
  return (store.loading ??= api.get('latest', { limit: 5 }).then((r) => {
    store.data = r;
    m.redraw();
  }));
}

/** The newest episodes across every show, each with a play button. Placed by Bespoke or fof/forum-widgets-core. */
export default class LatestEpisodes extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    load();
  }

  view() {
    if (!store.data) return <LoadingIndicator size="small" display="block" />;
    const { episodes, shows } = store.data;
    if (!episodes.length) return <p className="EarshotLatest-empty">{t('widget.empty')}</p>;

    return (
      <ul className="EarshotLatest">
        {episodes.map((e) => {
          const show = shows[e.showId];
          const playing = player.playing(e);
          return (
            <li className="EarshotLatest-item">
              <button
                type="button"
                className="EarshotLatest-play"
                onclick={() => player.toggle(e, show)}
                aria-label={playing ? t('player.pause') : t('player.play')}
              >
                {cover(show, 'EarshotCover', true)}
                <Icon name={playing ? 'fas fa-pause' : 'fas fa-play'} />
              </button>
              <div className="EarshotLatest-text">
                <Link className="EarshotLatest-title" href={discussionUrl(e)}>
                  {e.title}
                </Link>
                <span className="EarshotLatest-meta">{[show.title, date(e.publishedAt), minutes(e.duration)].filter(Boolean).join(' · ')}</span>
              </div>
            </li>
          );
        })}
        <li className="EarshotLatest-all">
          <Link href={app.route('earshot.index')}>{t('widget.all')}</Link>
        </li>
      </ul>
    );
  }
}
