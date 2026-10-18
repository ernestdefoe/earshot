import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import ItemList from 'flarum/common/utils/ItemList';
import { player } from '../player';
import { t, minutes, number, cover, episodeLabel } from '../util';
import { backdrop, meta, ring, people, subscribeButtons } from './heroBits';
import { follow } from './actions';

/**
 * A show's page hero, in Marquee's language. Add-ons extend sectionItems()
 * and actionItems().
 */
export default class ShowHero extends Component {
  view() {
    const show = this.attrs.show;

    return (
      <header className="Hero EarshotHero EarshotHero--show" style={{ '--earshot-accent': show.color || undefined }}>
        {backdrop(show.coverSmall || show.cover)}
        <div className="container EarshotHero-inner">
          <div className="EarshotHero-poster">{cover(show, 'EarshotCover', true)}</div>
          <div className="EarshotHero-body">
            <ul className="DiscussionHero-items">
              <li className="EarshotHero-pill">
                <Icon name="fas fa-podcast" /> {t('hero.podcast')}
              </li>
              {show.category ? <li className="EarshotHero-pill">{show.category}</li> : null}
              {show.follows ? <li className="EarshotHero-pill EarshotHero-pill--quiet">{t('hero.followed_feed')}</li> : null}
            </ul>
            <h1 className="DiscussionHero-title">{show.title}</h1>
            {meta([
              t('hero.episodes', { count: show.episodeCount }),
              this.attrs.averageDuration ? t('hero.around', { time: minutes(this.attrs.averageDuration) }) : null,
              (show.language || '').toUpperCase(),
              show.explicit ? t('hero.explicit') : t('hero.clean'),
            ])}
            {this.sectionItems().toArray()}
            <div className="EarshotHero-links">{this.actionItems().toArray()}</div>
          </div>
        </div>
      </header>
    );
  }

  sectionItems() {
    const items = new ItemList();
    const show = this.attrs.show;

    const rings = [];
    if (app.session.user && show.episodeCount) {
      rings.push(ring(show.played, t('hero.you_played'), (show.played / show.episodeCount) * 100, 'played'));
    }
    rings.push(ring(number(show.episodeCount), t('hero.episodes_label'), 100, 'episodes'));
    rings.push(ring(number(show.followerCount), t('hero.followers'), show.followerCount ? 100 : 0, 'followers'));
    items.add('scores', <div className="EarshotHero-scores">{rings}</div>, 100);

    if (show.tagline) items.add('tagline', <p className="EarshotHero-tagline">{show.tagline}</p>, 90);
    if (show.description) items.add('overview', <p className="EarshotHero-overview">{show.description}</p>, 80);
    items.add('people', people(t('hero.hosts'), show.hosts, t('hero.host')), 60);

    return items;
  }

  actionItems() {
    const items = new ItemList();
    const show = this.attrs.show;
    const latest = this.attrs.latest;

    if (latest) {
      const playing = player.playing(latest);
      const label = episodeLabel(latest);
      items.add(
        'play',
        <Button className="Button Button--primary" icon={playing ? 'fas fa-pause' : 'fas fa-play'} onclick={() => player.toggle(latest, show)}>
          {playing ? t('player.pause') : label ? t('hero.play_latest_numbered', { label }) : t('hero.play_latest')}
        </Button>,
        100
      );
    }
    items.add('follow', follow(show), 90);
    subscribeButtons(show).forEach((button, i) => button && items.add(`subscribe${i}`, button, 50 - i));
    (this.attrs.controls || []).forEach((button, i) => items.add(`control${i}`, button, -10 - i));

    return items;
  }
}
