import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import { t, avatar } from '../util';

/*
 * Pieces both heroes draw, in Marquee's hero language: always dark, the
 * artwork blown up behind a darkening gradient, a shadowed poster, pills,
 * a dotted meta line, score rings, a round-avatar people strip and
 * translucent buttons. Called as functions, never used as tags.
 */

export function backdrop(image) {
  return image ? (
    <div className="EarshotHero-backdrop" style={{ backgroundImage: `url("${encodeURI(image)}")` }} />
  ) : (
    <div className="EarshotHero-backdrop" />
  );
}

export function meta(parts) {
  const list = parts.filter(Boolean);
  return list.length ? (
    <ul className="EarshotHero-meta">
      {list.map((part) => (
        <li>{part}</li>
      ))}
    </ul>
  ) : null;
}

/** A Marquee score ring: value in the middle, the ring filled to `percent`. */
export function ring(value, label, percent, kind) {
  return (
    <div className={`EarshotHero-score EarshotHero-score--${kind}`}>
      <span className="EarshotHero-ring" style={{ '--v': Math.max(0, Math.min(100, percent)) }}>
        <b>{value}</b>
      </span>
      <span className="EarshotHero-scoreLabel">{label}</span>
    </div>
  );
}

export function people(title, users, role) {
  if (!users || !users.length) return null;
  return (
    <div className="EarshotHero-people">
      <h2 className="EarshotHero-sectionTitle">{title}</h2>
      <ul>
        {users.map((u) => (
          <li>
            <Link href={app.route('user', { username: u.slug })}>
              {avatar(u, 'EarshotHero-avatar')}
              <b>{u.displayName || u.username}</b>
              <span>{role}</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

const LINK_ICONS = {
  apple: 'fas fa-podcast',
  spotify: 'fab fa-spotify',
  youtube: 'fab fa-youtube',
  pocketcasts: 'fas fa-headphones',
  overcast: 'fas fa-tower-broadcast',
};

/** Subscribe buttons: each service the show is on, then its RSS feed. */
export function subscribeButtons(show) {
  const links = show.links || {};
  return [
    ...Object.keys(LINK_ICONS)
      .filter((k) => links[k])
      .map((k) => (
        <a className="Button EarshotHero-button" href={links[k]} target="_blank" rel="noopener noreferrer">
          <Icon name={LINK_ICONS[k]} />
          <span className="Button-label">{t(`links.${k}`)}</span>
        </a>
      )),
    show.feedUrl ? (
      <a className="Button EarshotHero-button" href={show.feedUrl} target="_blank" rel="noopener noreferrer" title={t('links.rss_help')}>
        <Icon name="fas fa-rss" />
        <span className="Button-label">{t('links.rss')}</span>
      </a>
    ) : null,
  ];
}
