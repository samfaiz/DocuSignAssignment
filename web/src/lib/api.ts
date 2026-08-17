/**
 * Thin API client.
 *
 * Two distinct callers live here and they authenticate differently: the admin
 * SPA carries a Sanctum bearer token, while the signer carries only the token
 * from the emailed link. They are kept apart deliberately — a signer must never
 * pick up an admin token that happens to be in localStorage on a shared machine.
 */

const TOKEN_KEY = 'signdesk.admin.token'

export class ApiError extends Error {
  // Declared as fields rather than constructor parameter properties: this
  // project builds with `erasableSyntaxOnly`, which bans TypeScript syntax that
  // emits runtime code.
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }

  /** First validation message for a field, if the server sent one. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

export const adminToken = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

type Options = {
  method?: string
  body?: unknown
  auth?: boolean
  raw?: boolean
}

async function request<T>(path: string, options: Options = {}): Promise<T> {
  const { method = 'GET', body, auth = false, raw = false } = options

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (auth) {
    const token = adminToken.get()
    if (token) headers.Authorization = `Bearer ${token}`
  }

  let payload: BodyInit | undefined
  if (body instanceof FormData) {
    // Let the browser set the multipart boundary.
    payload = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  const response = await fetch(`/api${path}`, { method, headers, body: payload })

  if (response.status === 401 && auth) {
    adminToken.clear()
  }

  if (!response.ok) {
    let message = response.statusText
    let errors: Record<string, string[]> = {}
    try {
      const data = await response.json()
      message = data.message ?? message
      errors = data.errors ?? {}
    } catch {
      // Non-JSON error body; the status line is all we have.
    }
    throw new ApiError(message, response.status, errors)
  }

  if (raw) return (await response.blob()) as T
  if (response.status === 204) return undefined as T
  return response.json() as Promise<T>
}

/* ------------------------------------------------------------------ admin */

export const api = {
  login: (email: string, password: string) =>
    request<{ token: string; user: User }>('/login', {
      method: 'POST',
      body: { email, password },
    }),

  me: () => request<{ user: User }>('/me', { auth: true }),

  logout: () => request<void>('/logout', { method: 'POST', auth: true }),

  uploadDocument: (file: File) => {
    const form = new FormData()
    form.append('file', file)
    return request<{ document: DocumentRecord }>('/documents', {
      method: 'POST',
      body: form,
      auth: true,
    })
  },

  /** Uses the bundled sample agreement, so no file of your own is needed. */
  useSampleDocument: () =>
    request<{ document: DocumentRecord }>('/documents/sample', {
      method: 'POST',
      body: {},
      auth: true,
    }),

  documentBlob: (id: number) =>
    request<Blob>(`/documents/${id}/download`, { auth: true, raw: true }),

  envelopes: () => request<Paginated<EnvelopeSummary>>('/envelopes', { auth: true }),

  createEnvelope: (payload: CreateEnvelopePayload) =>
    request<{ envelope: EnvelopeSummary }>('/envelopes', {
      method: 'POST',
      body: payload,
      auth: true,
    }),

  envelope: (uuid: string) =>
    request<{ envelope: EnvelopeDetail; audit_chain: ChainStatus }>(
      `/envelopes/${uuid}`,
      { auth: true },
    ),

  sendEnvelope: (uuid: string) =>
    request<{ envelope: EnvelopeSummary }>(`/envelopes/${uuid}/send`, {
      method: 'POST',
      auth: true,
    }),

  voidEnvelope: (uuid: string, reason: string) =>
    request<{ envelope: EnvelopeSummary }>(`/envelopes/${uuid}/void`, {
      method: 'POST',
      body: { reason },
      auth: true,
    }),

  recipientPhotoBlob: (uuid: string, recipientId: number) =>
    request<Blob>(`/envelopes/${uuid}/recipients/${recipientId}/photo`, {
      auth: true,
      raw: true,
    }),

  sealedBlob: (uuid: string) =>
    request<Blob>(`/envelopes/${uuid}/download`, { auth: true, raw: true }),

  verify: (file: File) => {
    const form = new FormData()
    form.append('file', file)
    return request<VerificationResult>('/verify', { method: 'POST', body: form })
  },

  mailSettings: () =>
    request<{ settings: MailSettings; presets: Record<string, MailPreset> }>(
      '/settings/mail',
      { auth: true },
    ),

  updateMailSettings: (payload: MailSettingsInput) =>
    request<{ settings: MailSettings }>('/settings/mail', {
      method: 'PUT',
      body: payload,
      auth: true,
    }),

  testMail: (to: string) =>
    request<{ ok: boolean; message: string; hint?: string }>('/settings/mail/test', {
      method: 'POST',
      body: { to },
      auth: true,
    }),
}

/* ------------------------------------------------------------------- demo */

/**
 * Reviewer conveniences. These routes only exist when the API has demo mode
 * enabled, so every call here must tolerate a 404 rather than assume it.
 */
export const demoApi = {
  info: () =>
    request<{
      admin: { email: string; password: string }
      mail_configured: boolean
    }>('/demo/info'),

  provision: () =>
    request<{
      sign_url: string
      envelope_uuid: string
      token: string
      admin: { email: string; password: string }
      mailpit_url: string
    }>('/demo/envelope', { method: 'POST', body: {} }),

  otp: (uuid: string, token: string) =>
    request<{ code: string | null; message: string }>(`/demo/otp/${uuid}?t=${token}`),
}

/* ----------------------------------------------------------------- signer */

export const signerApi = {
  load: (uuid: string, token: string) =>
    request<SignerPayload>(`/sign/${uuid}?t=${token}`),

  documentBlob: (uuid: string, token: string) =>
    request<Blob>(`/sign/${uuid}/document?t=${token}`, { raw: true }),

  requestOtp: (uuid: string, token: string) =>
    request<{ message: string; expires_in_minutes: number }>(
      `/sign/${uuid}/otp?t=${token}`,
      { method: 'POST', body: {} },
    ),

  verifyOtp: (uuid: string, token: string, code: string) =>
    request<{ message: string }>(`/sign/${uuid}/otp/verify?t=${token}`, {
      method: 'POST',
      body: { code },
    }),

  consent: (uuid: string, token: string) =>
    request<{ message: string }>(`/sign/${uuid}/consent?t=${token}`, {
      method: 'POST',
      body: { accepted: true },
    }),

  shareLocation: (
    uuid: string,
    token: string,
    payload: {
      consent: 'granted' | 'denied' | 'unsupported' | 'failed'
      latitude?: number
      longitude?: number
      accuracy?: number
    },
  ) =>
    request<{ location_consent: string; summary: string }>(
      `/sign/${uuid}/location?t=${token}`,
      { method: 'POST', body: payload },
    ),

  capturePhoto: (
    uuid: string,
    token: string,
    payload: { consent: 'granted' | 'denied' | 'unsupported' | 'failed'; image?: string },
  ) =>
    request<{ photo_consent: string }>(`/sign/${uuid}/photo?t=${token}`, {
      method: 'POST',
      body: payload,
    }),

  createSignature: (
    uuid: string,
    token: string,
    payload:
      | { kind: 'typed'; name: string; font: string }
      | { kind: 'drawn' | 'uploaded'; image: string },
  ) =>
    request<{ asset: SignatureAsset; preview: string }>(
      `/sign/${uuid}/signature?t=${token}`,
      { method: 'POST', body: payload },
    ),

  saveFields: (uuid: string, token: string, values: FieldValueInput[]) =>
    request<{ fields: SignerField[] }>(`/sign/${uuid}/fields?t=${token}`, {
      method: 'POST',
      body: { values },
    }),

  finish: (uuid: string, token: string) =>
    request<{ message: string; envelope_complete: boolean }>(
      `/sign/${uuid}/finish?t=${token}`,
      { method: 'POST', body: {} },
    ),

  decline: (uuid: string, token: string, reason: string) =>
    request<{ message: string }>(`/sign/${uuid}/decline?t=${token}`, {
      method: 'POST',
      body: { reason },
    }),
}

/* ------------------------------------------------------------------ types */

export type User = { id: number; name: string; email: string }

export type DocumentRecord = {
  id: number
  filename: string
  page_count: number
  sha256_original: string
  size_bytes: number
}

export type Paginated<T> = { data: T[]; total: number; current_page: number }

export type MailSettings = {
  mailer: string
  host: string | null
  port: number | null
  username: string | null
  encryption: string | null
  from_address: string | null
  from_name: string | null
  // The password itself is never sent back — only whether one is stored.
  has_password: boolean
  is_configured: boolean
  last_tested_at: string | null
  last_test_ok: boolean | null
  last_test_error: string | null
}

export type MailPreset = {
  label: string
  host: string
  port: number
  encryption: string | null
  note: string
}

export type MailSettingsInput = {
  host: string
  port: number
  username?: string | null
  password?: string | null
  encryption?: string | null
  from_address: string
  from_name: string
}

export type FieldType = 'signature' | 'initial' | 'date' | 'text' | 'checkbox'

export type PlacedField = {
  id?: number
  recipient_index: number
  type: FieldType
  page: number
  x: number
  y: number
  w: number
  h: number
  required?: boolean
}

export type RecipientInput = { name: string; email: string }

export type CreateEnvelopePayload = {
  document_id: number
  subject: string
  message?: string
  expires_in_days?: number
  require_photo?: boolean
  recipients: RecipientInput[]
  fields: PlacedField[]
}

export type EnvelopeSummary = {
  uuid: string
  subject: string
  status: string
  sent_at: string | null
  completed_at: string | null
  created_at: string
  recipients_count?: number
  document?: { filename: string; page_count: number }
  recipients?: { name: string; email: string; status: string; signed_at: string | null }[]
}

export type SealedDocument = {
  pades_level: string
  tsa_url: string | null
  sha256_sealed: string
  sha256_stamped: string
  certificate_subject: string | null
  certificate_serial: string | null
  page_count: number
  warnings: string[]
  sealed_at: string
}

export type AuditEventRecord = {
  seq: number
  type: string
  actor: string | null
  ip: string | null
  occurred_at: string
  hash: string
  prev_hash: string
  payload: Record<string, unknown>
}

// `recipients` and `document` are both narrowed here, so they are omitted from
// the summary rather than intersected with it — an intersection of two array
// types would leave the element type ambiguous.
export type EnvelopeDetail = Omit<EnvelopeSummary, 'recipients' | 'document'> & {
  message: string | null
  expires_at: string | null
  document: { id: number; filename: string; page_count: number; sha256_original: string }
  recipients: {
    id: number
    name: string
    email: string
    status: string
    signed_at: string | null
    auth_method: string | null
    last_ip: string | null
    location_consent: string | null
    location_summary: string
    latitude: number | null
    longitude: number | null
    photo_consent: string | null
    photo_summary: string
    has_photo: boolean
  }[]
  fields: PlacedField[]
  sealed_document: SealedDocument | null
  audit_events: AuditEventRecord[]
}

export type ChainStatus = {
  valid: boolean
  count: number
  broken_at: number | null
  reason: string | null
}

export type SignerField = {
  id: number
  type: FieldType
  page: number
  x: number
  y: number
  w: number
  h: number
  required: boolean
  value: { text_value: string | null; signature_asset_id: number | null } | null
}

export type SignatureAsset = { id: number; kind: string; sha256: string }

export type FieldValueInput = { field_id: number; text?: string; asset_id?: number }

export type SignerPayload = {
  envelope: {
    uuid: string
    subject: string
    message: string | null
    status: string
    expires_at: string | null
    sender: string
    require_photo: boolean
    document: { filename: string; page_count: number }
  }
  recipient: {
    name: string
    email: string
    role: string
    status: string
    signed_at: string | null
    otp_verified: boolean
    has_consented: boolean
    location_consent: string | null
    photo_consent: string | null
  }
  fields: SignerField[]
  my_turn: boolean
  fonts: Record<string, string>
  disclosure: { version: string; text: string; sha256: string }
}

export type VerificationResult = {
  signed: boolean
  intact: boolean
  trusted: boolean
  summary: string
  report: {
    signature_count: number
    revisions: number | null
    sha256: string
    signatures: {
      field_name: string
      kind: string
      intact?: boolean
      trusted?: boolean
      signer?: string | null
      coverage?: string
      error?: string
    }[]
  }
}
