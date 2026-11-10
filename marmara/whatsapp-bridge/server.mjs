// WhatsApp approval bridge for TryPost (Marmara AI fork).
//
// TryPost -> bridge: approval webhooks (post.approval_requested, post.approved,
// post.rejected) become WhatsApp messages with buttons.
// WhatsApp -> bridge: button taps and replies approve or reject the post, or are
// saved on the post as notes, through the TryPost API.
//
// No dependencies: Node 22 (fetch, crypto, http). Configuration is read from
// the environment; see config below.

import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const env = (name, fallback) => {
    const value = process.env[name];
    if (value === undefined || value === '') {
        if (fallback === undefined) throw new Error(`Missing environment variable ${name}`);
        return fallback;
    }
    return value;
};

const config = {
    port: Number(env('PORT', '8130')),
    basePath: env('BASE_PATH', '/wa-bridge').replace(/\/$/, ''),
    dataDir: env('DATA_DIR', '/data'),
    trypost: {
        apiUrl: env('TRYPOST_API_URL').replace(/\/$/, ''), // e.g. http://app/api
        publicUrl: env('TRYPOST_PUBLIC_URL').replace(/\/$/, ''), // e.g. https://sosyal-stage.marmaraai.com
        apiToken: env('TRYPOST_API_TOKEN'),
        webhookSecret: env('TRYPOST_WEBHOOK_SECRET'),
    },
    whatsapp: {
        graphVersion: env('WA_GRAPH_VERSION', 'v23.0'),
        phoneNumberId: env('WA_PHONE_NUMBER_ID'),
        accessToken: env('WA_ACCESS_TOKEN'),
        appSecret: env('WA_APP_SECRET'),
        verifyToken: env('WA_VERIFY_TOKEN'),
        // Approved utility template used when the 24-hour window is closed.
        // Body: {{1}} channel, {{2}} requester, {{3}} time, {{4}} excerpt.
        // Buttons: three quick replies (approve, request changes, comment).
        templateName: env('WA_TEMPLATE_NAME', 'gonderi_onay_bekliyor'),
        templateLanguage: env('WA_TEMPLATE_LANGUAGE', 'tr'),
    },
    // [{"name":"Yasir","phone":"905xxxxxxxxx","trypost_user_id":"...","approver":true}]
    people: JSON.parse(env('PEOPLE_JSON')),
    timeZone: env('TIME_ZONE', 'Europe/Istanbul'),
};

// ---------------------------------------------------------------- state

mkdirSync(config.dataDir, { recursive: true });
const statePath = join(config.dataDir, 'state.json');

const loadState = () => {
    try {
        return JSON.parse(readFileSync(statePath, 'utf8'));
    } catch {
        return { messages: {}, pending: {}, seen: [] };
    }
};

const state = loadState();

const saveState = () => {
    // Keep the files small: the last 2,000 message links and 500 seen ids.
    const messageIds = Object.keys(state.messages);
    for (const id of messageIds.slice(0, Math.max(0, messageIds.length - 2000))) delete state.messages[id];
    state.seen = state.seen.slice(-500);
    writeFileSync(`${statePath}.tmp`, JSON.stringify(state));
    renameSync(`${statePath}.tmp`, statePath);
};

const normalizePhone = (phone) => String(phone).replace(/\D/g, '');
const personByPhone = (phone) => config.people.find((p) => normalizePhone(p.phone) === normalizePhone(phone));
const personByUserId = (id) => config.people.find((p) => p.trypost_user_id === id);
const approvers = () => config.people.filter((p) => p.approver);

const log = (...args) => console.log(new Date().toISOString(), ...args);

// ---------------------------------------------------------------- signatures

const hmacMatches = (secret, rawBody, given) => {
    if (!given) return false;
    const expected = createHmac('sha256', secret).update(rawBody).digest('hex');
    const a = Buffer.from(expected);
    const b = Buffer.from(String(given).replace(/^sha256=/, ''));
    return a.length === b.length && timingSafeEqual(a, b);
};

// ---------------------------------------------------------------- TryPost API

const trypost = async (method, path, body) => {
    const response = await fetch(`${config.trypost.apiUrl}${path}`, {
        method,
        headers: {
            Authorization: `Bearer ${config.trypost.apiToken}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await response.text();
    let json = null;
    try {
        json = text ? JSON.parse(text) : null;
    } catch {
        json = null;
    }
    if (!response.ok) {
        const error = new Error(`TryPost ${method} ${path} -> ${response.status}`);
        error.status = response.status;
        error.body = json ?? text;
        throw error;
    }
    return json;
};

const addNote = (postId, author, text) =>
    trypost('POST', `/posts/${postId}/notes`, { body: `${author} (WhatsApp): ${text}`.slice(0, 2000) });

// ---------------------------------------------------------------- WhatsApp API

const whatsapp = async (payload) => {
    const response = await fetch(
        `https://graph.facebook.com/${config.whatsapp.graphVersion}/${config.whatsapp.phoneNumberId}/messages`,
        {
            method: 'POST',
            headers: { Authorization: `Bearer ${config.whatsapp.accessToken}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({ messaging_product: 'whatsapp', ...payload }),
        },
    );
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(`WhatsApp send failed: ${response.status} ${json?.error?.message ?? ''}`);
        error.code = json?.error?.code;
        throw error;
    }
    return json?.messages?.[0]?.id ?? null;
};

const sendText = (to, text, replyTo) =>
    whatsapp({
        to: normalizePhone(to),
        type: 'text',
        text: { body: text.slice(0, 4096), preview_url: false },
        ...(replyTo ? { context: { message_id: replyTo } } : {}),
    });

// A free-form message only reaches someone who wrote to us in the last 24
// hours; outside that window WhatsApp answers 131047 and we use the template.
const OUTSIDE_WINDOW = 131047;

// ---------------------------------------------------------------- formatting

const formatTime = (iso) => {
    if (!iso) return 'Zaman seçilmedi (sıraya eklenecek)';
    return new Intl.DateTimeFormat('tr-TR', {
        timeZone: config.timeZone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
};

const channelName = (post) => {
    const account = post.social_account ?? post.channel ?? post.socialAccount ?? {};
    const name = account.display_name ?? account.name ?? account.username ?? '';
    const platform = account.platform ?? post.platform ?? '';
    return [platform, name].filter(Boolean).join(' · ') || 'Kanal';
};

const excerpt = (content, length) => {
    const text = String(content ?? '').trim();
    if (!text) return '(Metin yok, sadece medya)';
    return text.length > length ? `${text.slice(0, length - 1)}…` : text;
};

const postUrl = (post) => `${config.trypost.publicUrl}/schedule?tab=approvals&edit=${post.id}`;

// ---------------------------------------------------------------- TryPost events

const remember = (messageId, postId) => {
    if (!messageId) return;
    state.messages[messageId] = postId;
};

const sendApprovalRequest = async (person, post, requesterName) => {
    const channel = channelName(post);
    const when = formatTime(post.scheduled_at);
    const body = [
        '📝 *Onay bekleyen gönderi*',
        `Kanal: ${channel}`,
        `Hazırlayan: ${requesterName}`,
        `Yayın: ${when}`,
        '',
        excerpt(post.content, 700),
        '',
        `Panelde aç: ${postUrl(post)}`,
        'Yorum için bu mesajı yanıtlayabilirsin.',
    ].join('\n');

    try {
        const id = await whatsapp({
            to: normalizePhone(person.phone),
            type: 'interactive',
            interactive: {
                type: 'button',
                body: { text: body.slice(0, 1024) },
                action: {
                    buttons: [
                        { type: 'reply', reply: { id: `approve:${post.id}`, title: 'Onayla' } },
                        { type: 'reply', reply: { id: `reject:${post.id}`, title: 'Düzeltme iste' } },
                        { type: 'reply', reply: { id: `comment:${post.id}`, title: 'Yorum yaz' } },
                    ],
                },
            },
        });
        remember(id, post.id);
    } catch (error) {
        if (error.code !== OUTSIDE_WINDOW) throw error;

        const param = (text) => ({ type: 'text', text: text.replace(/\s+/g, ' ').slice(0, 300) });
        const id = await whatsapp({
            to: normalizePhone(person.phone),
            type: 'template',
            template: {
                name: config.whatsapp.templateName,
                language: { code: config.whatsapp.templateLanguage },
                components: [
                    {
                        type: 'body',
                        parameters: [param(channel), param(requesterName), param(when), param(excerpt(post.content, 250))],
                    },
                    ...['approve', 'reject', 'comment'].map((action, index) => ({
                        type: 'button',
                        sub_type: 'quick_reply',
                        index: String(index),
                        parameters: [{ type: 'payload', payload: `${action}:${post.id}` }],
                    })),
                ],
            },
        });
        remember(id, post.id);
    }
};

const onTryPostEvent = async (event) => {
    const post = event.data ?? {};
    const approval = post.approval ?? {};
    const requester = approval.requested_by ?? post.author ?? {};
    const decider = approval.decided_by ?? {};
    const requesterName = personByUserId(requester.id)?.name ?? requester.name ?? 'Bir ekip üyesi';
    const deciderName = personByUserId(decider.id)?.name ?? decider.name ?? 'Bir yönetici';

    if (event.type === 'post.approval_requested') {
        for (const person of approvers()) {
            if (person.trypost_user_id && person.trypost_user_id === requester.id) continue;
            await sendApprovalRequest(person, post, requesterName).catch((error) =>
                log('approval request to', person.name, 'failed:', error.message),
            );
        }
        saveState();
        return;
    }

    if (event.type === 'post.approved' || event.type === 'post.rejected') {
        const approved = event.type === 'post.approved';
        const text = approved
            ? `✅ ${deciderName} gönderiyi onayladı: "${excerpt(post.content, 120)}"\n${formatTime(post.scheduled_at)}`
            : `↩️ ${deciderName} gönderi için düzeltme istedi: "${excerpt(post.content, 120)}"\nNotlar panelde: ${postUrl(post)}`;
        const recipients = [personByUserId(requester.id), ...approvers()]
            .filter(Boolean)
            .filter((p, i, list) => list.findIndex((q) => q.phone === p.phone) === i)
            .filter((p) => !p.trypost_user_id || p.trypost_user_id !== decider.id);
        for (const person of recipients) {
            // Free-form only: a person outside the 24-hour window misses this
            // update, which is fine, the panel and email still have it.
            await sendText(person.phone, text).catch((error) =>
                log('decision notice to', person.name, 'skipped:', error.message),
            );
        }
    }
};

// ---------------------------------------------------------------- WhatsApp events

const explainTryPostError = (error) => {
    if (error.status === 422) return 'Bu gönderi artık onay beklemiyor (biri karar vermiş ya da gönderi değişmiş).';
    if (error.status === 403) return 'Bu işlem için TryPost yetkim yok. Yöneticiye haber ver.';
    if (error.status === 404) return 'Gönderi bulunamadı, silinmiş olabilir.';
    return 'Bir sorun oldu, birazdan tekrar dene ya da paneli kullan.';
};

const handleAction = async (person, action, postId, messageId) => {
    if (!person.approver && action !== 'comment') {
        await sendText(person.phone, 'Onay verme yetkin yok, ama yorum yazabilirsin.', messageId);
        return;
    }

    if (action === 'approve') {
        try {
            await trypost('POST', `/posts/${postId}/approve`, {});
            await addNote(postId, person.name, 'Onayladı.').catch(() => {});
            await sendText(person.phone, '✅ Onaylandı. Gönderi planlanan zamanda yayınlanacak.', messageId);
        } catch (error) {
            log('approve failed', postId, error.message, JSON.stringify(error.body ?? ''));
            await sendText(person.phone, explainTryPostError(error), messageId);
        }
        return;
    }

    state.pending[normalizePhone(person.phone)] = { postId, mode: action, at: Date.now() };
    saveState();
    await sendText(
        person.phone,
        action === 'reject'
            ? 'Ne düzeltilsin? Yazdığın not gönderiye eklenecek ve gönderi hazırlayana geri dönecek. Vazgeçmek için "iptal" yaz.'
            : 'Yorumunu yaz, gönderinin notlarına eklenecek. Vazgeçmek için "iptal" yaz.',
        messageId,
    );
};

const handleText = async (person, message) => {
    const text = (message.text?.body ?? '').trim();
    const phone = normalizePhone(person.phone);
    const pending = state.pending[phone];
    const contextPostId = state.messages[message.context?.id];

    if (pending && Date.now() - pending.at < 30 * 60 * 1000) {
        delete state.pending[phone];
        saveState();

        if (/^iptal$/i.test(text)) {
            await sendText(person.phone, 'Tamam, vazgeçildi.', message.id);
            return;
        }

        try {
            await addNote(pending.postId, person.name, pending.mode === 'reject' ? `Düzeltme: ${text}` : text);
            if (pending.mode === 'reject') {
                await trypost('POST', `/posts/${pending.postId}/reject`, {});
                await sendText(person.phone, '↩️ Not eklendi, gönderi düzeltme için geri gönderildi.', message.id);
            } else {
                await sendText(person.phone, '💬 Yorum gönderiye eklendi.', message.id);
            }
        } catch (error) {
            log('pending action failed', pending.postId, error.message, JSON.stringify(error.body ?? ''));
            await sendText(person.phone, explainTryPostError(error), message.id);
        }
        return;
    }

    if (contextPostId) {
        try {
            await addNote(contextPostId, person.name, text);
            await sendText(person.phone, '💬 Yorum gönderiye eklendi.', message.id);
        } catch (error) {
            log('note failed', contextPostId, error.message);
            await sendText(person.phone, explainTryPostError(error), message.id);
        }
        return;
    }

    await sendText(
        person.phone,
        'Merhaba! Onay bekleyen gönderiler buraya gelecek. Bir gönderiye yorum yazmak için o gönderinin mesajını yanıtla.',
        message.id,
    );
};

const onWhatsAppMessage = async (message) => {
    if (state.seen.includes(message.id)) return;
    state.seen.push(message.id);
    saveState();

    const person = personByPhone(message.from);
    if (!person) {
        log('message from unknown number ignored');
        return;
    }

    const choice = message.interactive?.button_reply?.id ?? message.button?.payload;
    if (choice) {
        const [action, postId] = String(choice).split(':');
        if (['approve', 'reject', 'comment'].includes(action) && postId) {
            await handleAction(person, action, postId, message.id);
        }
        return;
    }

    if (message.type === 'text') {
        await handleText(person, message);
        return;
    }

    await sendText(person.phone, 'Şimdilik sadece yazılı mesajları ve butonları anlayabiliyorum.', message.id);
};

// ---------------------------------------------------------------- HTTP

const readBody = (request) =>
    new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        request.on('data', (chunk) => {
            size += chunk.length;
            if (size > 1_000_000) {
                reject(new Error('Body too large'));
                request.destroy();
                return;
            }
            chunks.push(chunk);
        });
        request.on('end', () => resolve(Buffer.concat(chunks)));
        request.on('error', reject);
    });

const reply = (response, status, body = '') => {
    response.writeHead(status, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end(body);
};

const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://bridge');
    const path = url.pathname.startsWith(config.basePath) ? url.pathname.slice(config.basePath.length) : url.pathname;

    try {
        if (request.method === 'GET' && path === '/health') return reply(response, 200, 'ok');

        // Meta's webhook verification handshake.
        if (request.method === 'GET' && path === '/whatsapp') {
            const ok =
                url.searchParams.get('hub.mode') === 'subscribe' &&
                url.searchParams.get('hub.verify_token') === config.whatsapp.verifyToken;
            return ok ? reply(response, 200, url.searchParams.get('hub.challenge') ?? '') : reply(response, 403);
        }

        if (request.method === 'POST' && path === '/whatsapp') {
            const raw = await readBody(request);
            if (!hmacMatches(config.whatsapp.appSecret, raw, request.headers['x-hub-signature-256'])) {
                return reply(response, 401);
            }
            reply(response, 200, 'ok');
            const payload = JSON.parse(raw.toString('utf8'));
            for (const entry of payload.entry ?? []) {
                for (const change of entry.changes ?? []) {
                    for (const message of change.value?.messages ?? []) {
                        await onWhatsAppMessage(message).catch((error) => log('whatsapp message failed:', error.message));
                    }
                }
            }
            return;
        }

        if (request.method === 'POST' && path === '/trypost') {
            const raw = await readBody(request);
            if (!hmacMatches(config.trypost.webhookSecret, raw, request.headers['x-webhook-signature'])) {
                return reply(response, 401);
            }
            reply(response, 200, 'ok');
            const event = JSON.parse(raw.toString('utf8'));
            if (event.type === 'webhook.test') return;
            await onTryPostEvent(event).catch((error) => log('trypost event failed:', error.message));
            return;
        }

        return reply(response, 404);
    } catch (error) {
        log('request failed:', error.message);
        if (!response.headersSent) reply(response, 500);
    }
});

server.listen(config.port, () => log(`whatsapp bridge listening on :${config.port}${config.basePath}`));
