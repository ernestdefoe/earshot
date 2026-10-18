import app from 'flarum/forum/app';
import stringToColor from 'flarum/common/utils/stringToColor';
import extractText from 'flarum/common/utils/extractText';

export const t = (key, params) => app.translator.trans(`ernestdefoe-earshot.forum.${key}`, params);

/** A translation as a plain string: trans() with parameters returns vnodes, which join() turns into "S,1,". */
export const ts = (key, params) => extractText(t(key, params));

export { clock, parseClock, bars } from './time';

/** 3000 → "50 min", 5400 → "1 hr 30 min". */
export function minutes(seconds) {
  if (!seconds) return null;
  const total = Math.round(seconds / 60);
  const h = Math.floor(total / 60);
  return h ? ts('time.hours_minutes', { hours: h, minutes: total % 60 }) : ts('time.minutes', { minutes: Math.max(1, total) });
}

export const date = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '');

export const number = (n) => Number(n || 0).toLocaleString();

/** The discussion page of an episode, as the API sends one. */
export const discussionUrl = (episode) =>
  app.route('discussion', { id: episode.slug ? `${episode.discussionId}-${episode.slug}` : episode.discussionId });

/** "S2 · EP 48", "EP 48", or nothing. */
export function episodeLabel(e) {
  if (!e.number) return e.season ? ts('hero.season', { season: e.season }) : null;
  return e.season ? ts('hero.season_episode', { season: e.season, episode: e.number }) : ts('hero.episode', { episode: e.number });
}

/** A show's accent: its own colour, else one derived from its title, as Flarum colours avatars. */
export const accent = (show) => show.color || `#${stringToColor(show.title)}`;

/** Flarum's avatar markup for a user as the Earshot API sends one. Called, never used as a tag. */
export function avatar(user, className = '') {
  const name = user.displayName || user.username;
  return user.avatarUrl ? (
    <img className={`Avatar ${className}`} src={user.avatarUrl} alt="" loading="lazy" />
  ) : (
    <span className={`Avatar ${className}`} style={{ '--avatar-bg': `#${stringToColor(name)}` }}>
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

/** A show's cover (`small`: the 400 px one), or its initials on its accent where it has none. */
export function cover(show, className = 'EarshotCover', small = false) {
  const src = small ? show.coverSmall || show.cover : show.cover;
  return src ? (
    <img className={className} src={src} alt="" loading="lazy" decoding="async" />
  ) : (
    <span className={`${className} ${className}--blank`} style={{ '--earshot-accent': accent(show) }} aria-hidden="true">
      {show.title
        .split(/\s+/)
        .slice(0, 3)
        .map((w) => w.charAt(0))
        .join('')
        .toUpperCase()}
    </span>
  );
}
