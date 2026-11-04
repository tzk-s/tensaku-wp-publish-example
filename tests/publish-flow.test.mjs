/**
 * 公開フローの確認（実際に起動した WordPress に HTTP で操作する）
 *
 * 先に `npm run playground:test` で一時的な WordPress を起動しておく（別のターミナル）。
 * テストは上から順に状態を進めるので、1 回の起動につき 1 回だけ実行する（もう一度実行するときは起動し直す）。
 *
 *   TPD_BASE_URL … 起動した WordPress の URL（既定は http://127.0.0.1:9400）
 */
import assert from 'node:assert/strict';
import { before, describe, it } from 'node:test';

const BASE = (process.env.TPD_BASE_URL ?? 'http://127.0.0.1:9400').replace(/\/$/, '');
const PAGE = `${BASE}/wp-admin/admin.php?page=tpd-faq`;
const REST = `${BASE}/wp-json/tensaku-demo/v1/faqs`;

/** ログイン情報は blueprint-local.json で作る一時環境専用のもの */
const USERS = {
  admin: ['admin', 'password'],
  editor: ['demo-editor', 'demo-editor-local-only'],
  subscriber: ['demo-subscriber', 'demo-subscriber-local-only'],
};

/** Cookie を持ち回る最小限のクライアント */
class Session {
  cookies = new Map();

  async request(url, init = {}) {
    const headers = new Headers(init.headers);
    if (this.cookies.size) headers.set('cookie', [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '));
    const response = await fetch(url, { ...init, headers, redirect: 'manual' });
    for (const line of response.headers.getSetCookie()) {
      const [pair] = line.split(';');
      const index = pair.indexOf('=');
      this.cookies.set(pair.slice(0, index), pair.slice(index + 1));
    }
    return response;
  }

  async login(username, password) {
    //  ブラウザーと同じく、ログイン画面を開いてから送信する（一時環境の最初のログインが失敗するのを避けるため）
    this.cookies.set('wordpress_test_cookie', 'WP%20Cookie%20check');
    await this.request(`${BASE}/wp-login.php`);
    const response = await this.request(`${BASE}/wp-login.php`, {
      method: 'POST',
      body: new URLSearchParams({ log: username, pwd: password, 'wp-submit': 'Log In', testcookie: '1' }),
    });
    assert.equal(response.status, 302);
    assert.match(response.headers.get('location') ?? '', /\/wp-admin\//, `${username} でログインできること`);
    return this;
  }

  async page() {
    const response = await this.request(PAGE);
    return { status: response.status, html: await response.text() };
  }

  /** 管理画面のフォームから、指定した操作の nonce を読む */
  async nonce(action) {
    const { html } = await this.page();
    const match = new RegExp(`name="action" value="${action}">[\\s\\S]*?name="_wpnonce" value="([0-9a-f]+)"`).exec(html);
    return match?.[1];
  }

  async post(fields) {
    const response = await this.request(`${BASE}/wp-admin/admin-post.php`, {
      method: 'POST',
      body: new URLSearchParams(fields),
    });
    return { status: response.status, location: response.headers.get('location') ?? '', body: await response.text() };
  }

  /** 「下書きを保存」。items は [{ question, answer, visible }] */
  async save(items, nonce) {
    const fields = { action: 'tpd_save', _wpnonce: nonce ?? (await this.nonce('tpd_save')) };
    items.forEach((item, index) => {
      fields[`tpd[items][${index}][question]`] = item.question;
      fields[`tpd[items][${index}][answer]`] = item.answer;
      if (item.visible) fields[`tpd[items][${index}][visible]`] = '1';
    });
    return this.post(fields);
  }

  /** 「公開に反映」 */
  async publish(nonce) {
    return this.post({ action: 'tpd_publish', _wpnonce: nonce ?? (await this.nonce('tpd_publish')) });
  }
}

const publicFaqs = async () => {
  const response = await fetch(REST);
  assert.equal(response.status, 200);
  return response.json();
};
const questions = body => body.items.map(item => item.question);

const EDITED = { question: '無料で試せますか？（保存のみの変更）', answer: '編集中の回答です。', visible: true };
const SECOND = { question: 'クライアントにもアカウントが必要ですか？', answer: 'いいえ。共有用のリンクを送れば、ゲストとしてコメントできます。', visible: true };

let admin;
let initial;

before(async () => {
  admin = await new Session().login(...USERS.admin);
  initial = await publicFaqs();
});

describe('公開済みの初期状態', () => {
  it('公開用の REST API は表示する質問だけを返し、管理用の項目（visible）は出さない', () => {
    assert.deepEqual(questions(initial), ['無料で試せますか？', 'クライアントにもアカウントが必要ですか？']);
    assert.deepEqual(Object.keys(initial.items[0]).sort(), ['answer', 'question']);
    assert.equal(JSON.stringify(initial).includes('社内用のメモ'), false);
  });

  it('公開用の REST API は読み取り専用（POST の入口は無い）', async () => {
    const response = await fetch(REST, { method: 'POST' });
    assert.equal(response.status, 404);
  });
});

describe('下書きを保存しても、公開用の値は変わらない', () => {
  it('管理者が保存すると、編集中の内容だけが変わる', async () => {
    const result = await admin.save([EDITED, SECOND]);
    assert.equal(result.status, 302);
    assert.match(result.location, /tpd_notice=saved/);

    const { html } = await admin.page();
    assert.match(html, /公開していない変更があります/);
    assert.match(html, /value="無料で試せますか？（保存のみの変更）"/);
    assert.deepEqual(await publicFaqs(), initial);
  });

  it('編集中の内容は、未認証の REST・標準の設定 API・公開ページの HTML に出ない', async () => {
    const draftText = '保存のみの変更';
    for (const url of [REST, `${BASE}/wp-json/`, `${BASE}/`, `${BASE}/feed/`]) {
      const text = await (await fetch(url)).text();
      assert.equal(text.includes(draftText), false, url);
    }
    //  標準の設定 API は未認証では読めず、管理者として読んでもこのデモの値は含まれない（register_setting していないため）
    assert.equal((await fetch(`${BASE}/wp-json/wp/v2/settings`)).status, 401);
    const restNonce = (await (await admin.request(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
    assert.match(restNonce, /^[0-9a-f]+$/, 'REST 用の nonce を受け取れること');
    const settings = await admin.request(`${BASE}/wp-json/wp/v2/settings`, { headers: { 'X-WP-Nonce': restNonce } });
    assert.equal(settings.status, 200);
    const body = await settings.text();
    assert.equal(body.includes(draftText), false);
    assert.equal(body.includes('tpd_faq'), false);
  });
});

describe('公開に反映できない場合は、公開用の値を変えない', () => {
  it('不正な nonce では公開しない', async () => {
    const result = await admin.publish('0123456789');
    assert.equal(result.status, 403);
    assert.deepEqual(await publicFaqs(), initial);
  });

  it('編集者は下書きを保存できるが、公開には反映できない', async () => {
    const editor = await new Session().login(...USERS.editor);
    const { status, html } = await editor.page();
    assert.equal(status, 200);
    assert.match(html, /公開に反映できるのは管理者だけです/);
    assert.equal(/name="action" value="tpd_publish"/.test(html), false, '公開のフォームを出さない');

    const saved = await editor.save([{ ...EDITED, answer: '編集者が直した回答です。' }, SECOND]);
    assert.match(saved.location, /tpd_notice=saved/);

    //  管理者の nonce を流用しても、権限の確認で止まる
    const result = await editor.publish(await admin.nonce('tpd_publish'));
    assert.equal(result.status, 403);
    assert.match(result.body, /公開に反映する権限がありません/);
    assert.deepEqual(await publicFaqs(), initial);
  });

  it('購読者は管理画面を開けず、保存もできない', async () => {
    const subscriber = await new Session().login(...USERS.subscriber);
    assert.notEqual((await subscriber.page()).status, 200);
    const result = await subscriber.save([{ question: '購読者の変更', answer: '購読者の変更', visible: true }], await admin.nonce('tpd_save'));
    assert.equal(result.status, 403);
    assert.equal((await admin.page()).html.includes('購読者の変更'), false);
  });

  it('不正な入力（回答が空・質問が長すぎる・表示する質問が 0 件）は保存しない', async () => {
    const cases = [
      [{ question: '回答が空の質問', answer: '', visible: true }],
      [{ question: 'あ'.repeat(101), answer: '回答', visible: true }],
      [{ question: '非表示だけ', answer: '回答', visible: false }],
    ];
    for (const items of cases) {
      const result = await admin.save(items);
      assert.match(result.location, /tpd_notice=invalid/);
      //  直後の画面は、誤りと入力した内容を 1 回だけ表示し直す
      const { html } = await admin.request(result.location).then(async r => ({ html: await r.text() }));
      assert.match(html, /保存していません/);
    }
    const { html } = await admin.page();
    assert.match(html, /value="無料で試せますか？（保存のみの変更）"/, '編集中の内容は直前に保存したまま');
    assert.deepEqual(await publicFaqs(), initial);
  });

  it('編集中の内容が不正なら、公開に反映しても以前の公開値を保つ', async () => {
    const corrupted = await admin.post({ action: 'tpd_test_corrupt_draft' });
    assert.equal(corrupted.status, 200);
    const result = await admin.publish();
    assert.match(result.location, /tpd_notice=publish_failed/);
    assert.deepEqual(await publicFaqs(), initial);
  });
});

describe('公開に反映すると、公開用の値が変わる', () => {
  it('管理者が保存して「公開に反映」を押すと、公開用の REST API が新しい内容を返す', async () => {
    const saved = await admin.save([{ ...EDITED, answer: '1 行目\r\n2 行目' }, { ...SECOND, answer: '<b>タグ</b>は保存時に取り除きます。' }]);
    assert.match(saved.location, /tpd_notice=saved/);
    assert.deepEqual(await publicFaqs(), initial, '保存しただけでは変わらない');

    const result = await admin.publish();
    assert.match(result.location, /tpd_notice=published/);

    const after = await publicFaqs();
    assert.deepEqual(questions(after), [EDITED.question, SECOND.question]);
    assert.equal(after.items[0].answer, '1 行目\n2 行目', '改行は \\n にそろえる');
    assert.equal(after.items[1].answer, 'タグは保存時に取り除きます。');
    assert.match(after.publishedAt, /^\d{4}-\d{2}-\d{2}T/);
    assert.match((await admin.page()).html, /公開中の内容と同じです/);
  });
});
