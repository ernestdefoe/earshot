import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import ItemList from 'flarum/common/utils/ItemList';
import { api } from '../api';
import { t, ts, clock } from '../util';

/** How long a file or address plays, read by the browser: PHP cannot read audio. */
function measure(src) {
  return new Promise((resolve) => {
    const a = new Audio();
    a.preload = 'metadata';
    const done = (v) => {
      a.removeAttribute('src');
      resolve(v);
    };
    a.onloadedmetadata = () => done(isFinite(a.duration) ? Math.round(a.duration) : null);
    a.onerror = () => done(null);
    setTimeout(() => done(null), 8000);
    a.src = src;
  });
}

/**
 * Publish or change an episode: an audio file uploaded here (with progress)
 * or the address of one hosted elsewhere, its notes and its chapters.
 */
export default class EpisodeModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    const e = this.attrs.episode || {};
    this.isNew = !e.id;
    this.f = {
      title: Stream(e.title || ''),
      season: Stream(e.season || ''),
      number: Stream(e.number || ''),
      summary: Stream(e.summary || ''),
      audioUrl: Stream(''),
      chapters: Stream((e.chapters || []).map(([s, title]) => `${clock(s)} ${title}`).join('\n')),
      explicit: Stream(!!e.explicit),
    };
    this.source = 'file';
    this.file = null;
    this.progress = null;
  }

  className() {
    return 'EarshotModal Modal--medium';
  }

  title() {
    return this.isNew ? t('modal.new_episode', { show: this.attrs.show.title }) : t('modal.edit_episode');
  }

  content() {
    const f = this.f;
    const sourceButton = (key) => (
      <Button className={`Button ${this.source === key ? 'Button--primary' : ''}`} onclick={() => (this.source = key)}>
        {t(`modal.audio_${key}`)}
      </Button>
    );

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="earshot-ep-title">{t('modal.title')}</label>
            <input id="earshot-ep-title" className="FormControl" required maxlength="200" bidi={f.title} />
          </div>
          <div className="EarshotModal-row">
            <div className="Form-group">
              <label for="earshot-ep-season">{t('modal.season')}</label>
              <input id="earshot-ep-season" type="number" min="1" className="FormControl" bidi={f.season} />
            </div>
            <div className="Form-group">
              <label for="earshot-ep-number">{t('modal.number')}</label>
              <input id="earshot-ep-number" type="number" min="1" className="FormControl" bidi={f.number} />
            </div>
          </div>
          <div className="Form-group">
            <label>{this.isNew ? t('modal.audio') : t('modal.replace_audio')}</label>
            <div className="ButtonGroup EarshotModal-source">
              {sourceButton('file')}
              {sourceButton('url')}
            </div>
            {this.source === 'file' ? (
              <input
                type="file"
                accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,.mp3,.m4a,.ogg,.opus"
                className="FormControl"
                onchange={(e) => (this.file = e.target.files[0])}
                aria-label={t('modal.audio_file')}
              />
            ) : (
              <input type="url" className="FormControl" placeholder="https://…/episode.mp3" bidi={f.audioUrl} aria-label={t('modal.audio_url')} />
            )}
            <div className="helpText">{t('modal.audio_help')}</div>
          </div>
          <div className="Form-group">
            <label for="earshot-ep-summary">{t('modal.notes')}</label>
            <textarea id="earshot-ep-summary" className="FormControl" rows="5" maxlength="5000" bidi={f.summary} />
            <div className="helpText">{t('modal.notes_help')}</div>
          </div>
          <div className="Form-group">
            <label for="earshot-ep-chapters">{t('modal.chapters')}</label>
            <textarea
              id="earshot-ep-chapters"
              className="FormControl"
              rows="4"
              placeholder={'0:00 Cold open\n12:30 The main story'}
              bidi={f.chapters}
            />
            <div className="helpText">{t('modal.chapters_help')}</div>
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={f.explicit()} onchange={(e) => f.explicit(e.target.checked)} /> {t('modal.explicit')}
            </label>
          </div>
          {this.fields().toArray()}
          <div className="Form-group EarshotModal-actions">
            <Button type="submit" className="Button Button--primary" loading={this.loading}>
              {this.progress !== null
                ? t('modal.uploading', { percent: Math.round(this.progress * 100) })
                : this.isNew
                  ? t('modal.publish')
                  : t('modal.save')}
            </Button>
            {!this.isNew ? (
              <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove()}>
                {t('modal.delete_episode')}
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

  async onsubmit(e) {
    e.preventDefault();
    const f = this.f;
    const show = this.attrs.show;
    this.loading = true;
    const body = {
      title: f.title(),
      season: f.season(),
      number: f.number(),
      summary: f.summary(),
      chapters: f.chapters(),
      explicit: f.explicit(),
      ...this.data(),
    };

    try {
      if (this.source === 'file' && this.file) {
        const local = URL.createObjectURL(this.file);
        body.duration = await measure(local);
        URL.revokeObjectURL(local);
        this.progress = 0;
        const uploaded = await api.upload(`shows/${show.id}/upload/audio`, this.file, (p) => {
          this.progress = p;
          m.redraw();
        });
        body.audioPath = uploaded.path;
      } else if (this.source === 'url' && f.audioUrl()) {
        body.audioUrl = f.audioUrl();
        body.duration = await measure(f.audioUrl());
      }
      this.progress = null;
      const r = this.isNew ? await api.post(`shows/${show.id}/episodes`, body) : await api.patch(`episodes/${this.attrs.episode.id}`, body);
      this.hide();
      this.attrs.onsave && this.attrs.onsave(r.episode);
    } catch (error) {
      this.loading = false;
      this.progress = null;
      if (typeof error === 'string') app.alerts.show({ type: 'error' }, error);
      m.redraw();
    }
  }

  remove() {
    if (!confirm(ts('modal.delete_episode_confirm'))) return;
    api.del(`episodes/${this.attrs.episode.id}`).then(() => {
      this.hide();
      m.route.set(app.route('earshot.show', { slug: this.attrs.show.slug }));
    });
  }
}
