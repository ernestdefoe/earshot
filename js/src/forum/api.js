import app from 'flarum/forum/app';

/*
 * Earshot's API. Plain JSON endpoints rather than JSON:API resources: the
 * pages ask for exactly what they draw, in one request each.
 */
const url = (path) => `${app.forum.attribute('apiUrl')}/earshot/${path}`;

export const api = {
  get: (path, params) => app.request({ method: 'GET', url: url(path), params }),
  post: (path, body) => app.request({ method: 'POST', url: url(path), body }),
  patch: (path, body) => app.request({ method: 'PATCH', url: url(path), body }),
  del: (path) => app.request({ method: 'DELETE', url: url(path) }),
  /** A file upload, with progress: app.request cannot report it. */
  upload(path, file, onprogress) {
    return new Promise((resolve, reject) => {
      const body = new FormData();
      body.append('file', file);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url(path));
      xhr.setRequestHeader('X-CSRF-Token', app.session.csrfToken);
      xhr.upload.onprogress = (e) => e.lengthComputable && onprogress && onprogress(e.loaded / e.total);
      xhr.onload = () => {
        let json = null;
        try {
          json = JSON.parse(xhr.responseText);
        } catch (e) {}
        if (xhr.status >= 200 && xhr.status < 300) resolve(json);
        else reject(json?.errors?.[0]?.detail || xhr.statusText || 'Upload failed');
      };
      xhr.onerror = () => reject('Upload failed');
      xhr.send(body);
    });
  },
};
