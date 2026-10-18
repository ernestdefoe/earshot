import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import ItemList from 'flarum/common/utils/ItemList';
import { api } from '../api';
import { t, ts } from '../util';

const LINKS = ['apple', 'spotify', 'youtube', 'pocketcasts', 'overcast'];

/**
 * Start or change a show. Hosts edit the details; moderators also choose the
 * hosts, the tag its discussions start in, and a feed to follow.
 */
export default class ShowModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    const s = this.attrs.show || {};
    this.isNew = !s.id;
    this.manager = this.isNew || s.canManage;
    this.f = {
      title: Stream(s.title || ''),
      tagline: Stream(s.tagline || ''),
      description: Stream(s.description || ''),
      category: Stream(s.category || ''),
      language: Stream(s.language || (app.translator.getLocale && app.translator.getLocale()) || 'en'),
      color: Stream(s.color || ''),
      explicit: Stream(!!s.explicit),
      feedUrl: Stream(s.follows ? s.feedUrl : ''),
      tagId: Stream(s.tagId ? String(s.tagId) : ''),
      hosts: Stream((s.hosts || []).map((u) => u.username).join(', ')),
    };
    this.links = {};
    LINKS.forEach((k) => (this.links[k] = Stream((s.links && s.links[k]) || '')));
    this.file = null;
    this.progress = null;
    this.tags = app.forum.attribute && flarum.extensions['flarum-tags'] ? app.store.all('tags') : [];
  }

  className() {
    return 'EarshotModal Modal--medium';
  }

  title() {
    return this.isNew ? t('modal.new_show') : t('modal.edit_show');
  }

  content() {
    const f = this.f;
    const field = (key, label, input, help) => (
      <div className="Form-group">
        <label for={`earshot-${key}`}>{label}</label>
        {input}
        {help ? <div className="helpText">{help}</div> : null}
      </div>
    );
    const text = (key, attrs = {}) => <input id={`earshot-${key}`} className="FormControl" bidi={f[key]} {...attrs} />;

    return (
      <div className="Modal-body">
        <div className="Form">
          {field('title', t('modal.title'), text('title', { maxlength: 120 }), this.isNew ? t('modal.title_help') : null)}
          {field('tagline', t('modal.tagline'), text('tagline', { maxlength: 200 }))}
          {field(
            'description',
            t('modal.description'),
            <textarea id="earshot-description" className="FormControl" rows="4" maxlength="4000" bidi={f.description} />
          )}
          {field(
            'cover',
            t('modal.cover'),
            <input
              id="earshot-cover"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="FormControl"
              onchange={(e) => (this.file = e.target.files[0])}
            />,
            t('modal.cover_help')
          )}
          <div className="EarshotModal-row">
            {field('category', t('modal.category'), text('category', { maxlength: 80, placeholder: t('modal.category_placeholder') }))}
            {field('language', t('modal.language'), text('language', { maxlength: 12 }))}
            {field(
              'color',
              t('modal.color'),
              <input
                id="earshot-color"
                type="color"
                className="FormControl"
                value={f.color() || '#1f3a5f'}
                oninput={(e) => f.color(e.target.value)}
              />
            )}
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={f.explicit()} onchange={(e) => f.explicit(e.target.checked)} /> {t('modal.explicit')}
            </label>
          </div>
          <fieldset className="EarshotModal-links">
            <legend>{t('modal.links')}</legend>
            {LINKS.map((k) => (
              <div className="Form-group">
                <label for={`earshot-link-${k}`}>{t(`links.${k}`)}</label>
                <input id={`earshot-link-${k}`} type="url" className="FormControl" placeholder="https://" bidi={this.links[k]} />
              </div>
            ))}
          </fieldset>
          {this.manager ? (
            <fieldset>
              <legend>{t('modal.moderation')}</legend>
              {field('hosts', t('modal.hosts'), text('hosts', { placeholder: t('modal.hosts_placeholder') }), t('modal.hosts_help'))}
              {field('feedUrl', t('modal.feed_url'), text('feedUrl', { type: 'url', placeholder: 'https://' }), t('modal.feed_url_help'))}
              {this.tags.length
                ? field(
                    'tagId',
                    t('modal.tag'),
                    <select id="earshot-tagId" className="FormControl" value={f.tagId()} onchange={(e) => f.tagId(e.target.value)}>
                      <option value="">{t('modal.no_tag')}</option>
                      {this.tags.map((tag) => (
                        <option value={tag.id()}>{tag.name()}</option>
                      ))}
                    </select>,
                    t('modal.tag_help')
                  )
                : null}
            </fieldset>
          ) : null}
          {this.fields().toArray()}
          <div className="Form-group EarshotModal-actions">
            <Button type="submit" className="Button Button--primary" loading={this.loading}>
              {this.progress !== null ? t('modal.uploading', { percent: Math.round(this.progress * 100) }) : t('modal.save')}
            </Button>
            {!this.isNew && this.attrs.show.canManage ? (
              <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove()}>
                {t('modal.delete_show')}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  /** Extra form groups, before the buttons. Add-ons add here and send their values from data(). */
  fields() {
    return new ItemList();
  }

  /** What is sent besides the core fields. Add-ons extend it. */
  data() {
    return {};
  }

  onsubmit(e) {
    e.preventDefault();
    this.loading = true;
    const f = this.f;
    const body = {
      title: f.title(),
      tagline: f.tagline(),
      description: f.description(),
      category: f.category(),
      language: f.language(),
      color: f.color(),
      explicit: f.explicit(),
      links: Object.fromEntries(LINKS.map((k) => [k, this.links[k]()])),
      ...this.data(),
    };
    if (this.manager) {
      body.feedUrl = f.feedUrl();
      body.tagId = f.tagId();
      body.hosts = f
        .hosts()
        .split(',')
        .map((n) => n.trim())
        .filter(Boolean);
    }
    const save = this.isNew ? api.post('shows', body) : api.patch(`shows/${this.attrs.show.id}`, body);

    save
      .then((r) => {
        if (!this.file) return r;
        this.progress = 0;
        return api.upload(`shows/${r.show.id}/upload/cover`, this.file, (p) => {
          this.progress = p;
          m.redraw();
        });
      })
      .then(
        (r) => {
          this.hide();
          this.attrs.onsave && this.attrs.onsave(r.show);
        },
        (error) => {
          this.loading = false;
          this.progress = null;
          if (typeof error === 'string') app.alerts.show({ type: 'error' }, error);
          m.redraw();
        }
      );
  }

  remove() {
    if (!confirm(ts('modal.delete_show_confirm'))) return;
    api.del(`shows/${this.attrs.show.id}`).then(() => {
      this.hide();
      m.route.set(app.route('earshot.index'));
    });
  }
}
