import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import { api } from '../api';
import { t } from '../util';

/** Follow / Following, for a show as the API sends one. Updates it in place. */
export function follow(show) {
  if (!app.session.user || show.canFollow === false) return null;
  return (
    <Button
      className={`Button EarshotHero-button ${show.following ? 'EarshotHero-button--on' : ''}`}
      icon={show.following ? 'fas fa-check' : 'fas fa-bell'}
      aria-pressed={show.following ? 'true' : 'false'}
      onclick={() => {
        const next = !show.following;
        show.following = next;
        api.post(`shows/${show.id}/follow`, { follow: next }).then(
          (r) => {
            show.following = r.following;
            show.followerCount = r.followerCount;
            m.redraw();
          },
          () => {
            show.following = !next;
            m.redraw();
          }
        );
      }}
    >
      {show.following ? t('hero.following') : t('hero.follow')}
    </Button>
  );
}
