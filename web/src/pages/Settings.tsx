import { useEffect, useState } from 'react'
import {
  api,
  ApiError,
  type MailPreset,
  type MailSettings,
  type MailSettingsInput,
} from '../lib/api'

const EMPTY: MailSettingsInput = {
  host: '',
  port: 587,
  username: '',
  password: '',
  encryption: 'tls',
  from_address: '',
  from_name: 'SignDesk',
}

/**
 * SMTP configuration.
 *
 * Mail is load-bearing rather than incidental here: signing links and one-time
 * passcodes travel over it, so an operator who cannot fix mail delivery cannot
 * fix the product. Hence a real settings screen rather than an .env edit.
 */
export default function Settings() {
  const [form, setForm] = useState<MailSettingsInput>(EMPTY)
  const [saved, setSaved] = useState<MailSettings | null>(null)
  const [presets, setPresets] = useState<Record<string, MailPreset>>({})
  const [activeNote, setActiveNote] = useState<string | null>(null)

  const [testTo, setTestTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ ok: boolean; text: string; hint?: string } | null>(null)

  useEffect(() => {
    api
      .mailSettings()
      .then(({ settings, presets }) => {
        setSaved(settings)
        setPresets(presets)
        setForm({
          host: settings.host ?? '',
          port: settings.port ?? 587,
          username: settings.username ?? '',
          // Left blank deliberately: the server never returns the stored
          // password, and an empty field means "keep the existing one".
          password: '',
          encryption: settings.encryption ?? 'tls',
          from_address: settings.from_address ?? '',
          from_name: settings.from_name ?? 'SignDesk',
        })
      })
      .catch(() => setMessage({ ok: false, text: 'Could not load mail settings.' }))
  }, [])

  function applyPreset(key: string) {
    const preset = presets[key]
    if (!preset) return
    setForm((f) => ({
      ...f,
      host: preset.host,
      port: preset.port,
      encryption: preset.encryption,
    }))
    setActiveNote(preset.note)
  }

  function field<K extends keyof MailSettingsInput>(key: K, value: MailSettingsInput[K]) {
    setForm((f) => ({ ...f, [key]: value }))
  }

  async function save(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setMessage(null)
    try {
      const { settings } = await api.updateMailSettings(form)
      setSaved(settings)
      setForm((f) => ({ ...f, password: '' }))
      setMessage({ ok: true, text: 'Settings saved. Send a test to confirm they work.' })
    } catch (e) {
      setMessage({
        ok: false,
        text: e instanceof ApiError ? e.message : 'Could not save settings.',
      })
    } finally {
      setBusy(false)
    }
  }

  async function sendTest() {
    setBusy(true)
    setMessage(null)
    try {
      const result = await api.testMail(testTo)
      setMessage({ ok: result.ok, text: result.message, hint: result.hint })
      setSaved(await api.mailSettings().then((r) => r.settings))
    } catch (e) {
      const detail = e instanceof ApiError ? e.message : 'Could not send the test message.'
      setMessage({ ok: false, text: detail })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-1 text-xl font-semibold tracking-tight">Email settings</h1>
      <p className="mb-6 text-sm text-slate-500">
        Signing invitations and one-time passcodes are sent from here. Without
        working SMTP, nobody can sign anything.
      </p>

      {saved && (
        <div
          className={`mb-5 rounded-lg border p-4 ${
            saved.is_configured
              ? 'border-emerald-200 bg-emerald-50'
              : 'border-amber-200 bg-amber-50'
          }`}
        >
          <p
            className={`text-sm font-medium ${
              saved.is_configured ? 'text-emerald-800' : 'text-amber-900'
            }`}
          >
            {saved.is_configured
              ? 'Mail is configured.'
              : 'Not configured yet — falling back to the server environment.'}
          </p>
          {saved.last_tested_at && (
            <p className="mt-1 text-xs text-slate-600">
              Last test {saved.last_test_ok ? 'succeeded' : 'failed'} on{' '}
              {new Date(saved.last_tested_at).toLocaleString()}
              {saved.last_test_error ? ` — ${saved.last_test_error}` : ''}
            </p>
          )}
        </div>
      )}

      {message && (
        <div
          className={`mb-5 rounded-md px-4 py-3 text-sm ${
            message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'
          }`}
        >
          {message.text}
          {message.hint && <p className="mt-1.5 text-xs opacity-90">{message.hint}</p>}
        </div>
      )}

      <form onSubmit={save} className="space-y-5 rounded-lg border border-slate-200 bg-white p-6">
        <div>
          <p className="mb-2 text-sm font-medium text-slate-700">Start from a provider</p>
          <div className="flex flex-wrap gap-2">
            {Object.entries(presets).map(([key, preset]) => (
              <button
                key={key}
                type="button"
                onClick={() => applyPreset(key)}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:border-blue-600 hover:text-blue-700"
              >
                {preset.label}
              </button>
            ))}
          </div>
          {activeNote && (
            <p className="mt-2 rounded-md bg-blue-50 px-3 py-2 text-xs leading-relaxed text-blue-900">
              {activeNote}
            </p>
          )}
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <div className="sm:col-span-2">
            <Label>SMTP host</Label>
            <Input
              value={form.host}
              onChange={(v) => field('host', v)}
              placeholder="smtp.gmail.com"
              required
            />
          </div>
          <div>
            <Label>Port</Label>
            <Input
              type="number"
              value={String(form.port)}
              onChange={(v) => field('port', Number(v))}
              required
            />
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label>Username</Label>
            <Input
              value={form.username ?? ''}
              onChange={(v) => field('username', v)}
              placeholder="you@gmail.com"
            />
          </div>
          <div>
            <Label>
              Password{' '}
              {saved?.has_password && (
                <span className="font-normal text-slate-400">(saved — leave blank to keep)</span>
              )}
            </Label>
            <Input
              type="password"
              value={form.password ?? ''}
              onChange={(v) => field('password', v)}
              placeholder={saved?.has_password ? '••••••••••••••••' : 'App password'}
              autoComplete="new-password"
            />
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <div>
            <Label>Encryption</Label>
            <select
              value={form.encryption ?? ''}
              onChange={(e) => field('encryption', e.target.value || null)}
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
            >
              <option value="tls">TLS (port 587)</option>
              <option value="ssl">SSL (port 465)</option>
              <option value="">None</option>
            </select>
          </div>
          <div>
            <Label>From address</Label>
            <Input
              type="email"
              value={form.from_address}
              onChange={(v) => field('from_address', v)}
              placeholder="no-reply@yourdomain.com"
              required
            />
          </div>
          <div>
            <Label>From name</Label>
            <Input
              value={form.from_name}
              onChange={(v) => field('from_name', v)}
              required
            />
          </div>
        </div>

        <button
          type="submit"
          disabled={busy}
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-800 disabled:bg-slate-300"
        >
          {busy ? 'Saving…' : 'Save settings'}
        </button>
      </form>

      <section className="mt-6 rounded-lg border border-slate-200 bg-white p-6">
        <h2 className="text-sm font-semibold">Send a test message</h2>
        <p className="mb-3 mt-1 text-xs leading-relaxed text-slate-500">
          Saving credentials proves nothing — SMTP fails for host, port, TLS and
          authentication reasons that all look the same from a form. This sends a
          real message.
        </p>
        <div className="flex gap-2">
          <input
            type="email"
            value={testTo}
            onChange={(e) => setTestTo(e.target.value)}
            placeholder="your@email.com"
            className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
          />
          <button
            type="button"
            onClick={sendTest}
            disabled={busy || !testTo.includes('@')}
            className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium transition hover:bg-slate-50 disabled:opacity-50"
          >
            Send test
          </button>
        </div>
      </section>
    </div>
  )
}

function Label({ children }: { children: React.ReactNode }) {
  return <label className="mb-1 block text-sm font-medium text-slate-700">{children}</label>
}

function Input({
  value,
  onChange,
  type = 'text',
  ...rest
}: {
  value: string
  onChange: (value: string) => void
  type?: string
  placeholder?: string
  required?: boolean
  autoComplete?: string
}) {
  return (
    <input
      type={type}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
      {...rest}
    />
  )
}
