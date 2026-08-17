<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SigningInvitation;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureField;
use App\Services\AuditLogger;
use App\Services\SignerTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnvelopeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SignerTokenService $tokens,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $envelopes = Envelope::where('sender_id', $request->user()->id)
            ->with(['document:id,filename,page_count', 'recipients:id,envelope_id,name,email,status,signed_at'])
            ->withCount('recipients')
            ->latest()
            ->paginate(20);

        return response()->json($envelopes);
    }

    public function show(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeSender($request, $envelope);

        $envelope->load([
            'document', 'recipients', 'fields',
            'sealedDocument', 'auditEvents',
        ]);

        return response()->json([
            'envelope' => $envelope,
            // Recomputed on every read rather than cached: a cached "valid"
            // would be exactly the thing an attacker would want to poison.
            'audit_chain' => $this->audit->verifyChain($envelope),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:documents,id'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'require_photo' => ['nullable', 'boolean'],

            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*.name' => ['required', 'string', 'max:255'],
            'recipients.*.email' => ['required', 'email', 'max:255'],
            'recipients.*.phone' => ['nullable', 'string', 'max:32'],
            'recipients.*.role' => ['nullable', 'in:signer,viewer,approver'],
            'recipients.*.routing_order' => ['nullable', 'integer', 'min:1', 'max:100'],

            'fields' => ['required', 'array', 'min:1'],
            'fields.*.recipient_index' => ['required', 'integer', 'min:0'],
            'fields.*.type' => ['required', 'in:signature,initial,date,text,checkbox'],
            'fields.*.page' => ['required', 'integer', 'min:0'],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.w' => ['required', 'numeric', 'gt:0', 'max:1'],
            'fields.*.h' => ['required', 'numeric', 'gt:0', 'max:1'],
            'fields.*.required' => ['nullable', 'boolean'],
        ]);

        $document = Document::findOrFail($data['document_id']);
        abort_unless($document->owner_id === $request->user()->id, 403);

        // Every field must point at a recipient that exists in this payload,
        // and at a page that exists in this document.
        foreach ($data['fields'] as $i => $field) {
            abort_if(
                $field['recipient_index'] >= count($data['recipients']),
                422,
                "fields.{$i}.recipient_index does not match any recipient"
            );
            abort_if(
                $field['page'] >= $document->page_count,
                422,
                "fields.{$i}.page is beyond the document's {$document->page_count} page(s)"
            );
        }

        $envelope = DB::transaction(function () use ($data, $document, $request) {
            $envelope = Envelope::create([
                'document_id' => $document->id,
                'sender_id' => $request->user()->id,
                'subject' => $data['subject'],
                'message' => $data['message'] ?? null,
                'status' => Envelope::STATUS_DRAFT,
                'require_photo' => (bool) ($data['require_photo'] ?? false),
                'expires_at' => isset($data['expires_in_days'])
                    ? Carbon::now('UTC')->addDays($data['expires_in_days'])
                    : null,
            ]);

            $recipients = [];
            foreach ($data['recipients'] as $index => $row) {
                $recipient = new Recipient([
                    'envelope_id' => $envelope->id,
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'] ?? null,
                    'role' => $row['role'] ?? Recipient::ROLE_SIGNER,
                    'routing_order' => $row['routing_order'] ?? 1,
                    'status' => Recipient::STATUS_PENDING,
                ]);

                // A token is minted now so the column's NOT NULL/UNIQUE holds;
                // it is reissued at send time so a draft that sits around does
                // not burn its link's lifetime.
                $recipient->access_token_hash = hash('sha256', bin2hex(random_bytes(32)));
                $recipient->save();

                $recipients[$index] = $recipient;
            }

            foreach ($data['fields'] as $field) {
                SignatureField::create([
                    'envelope_id' => $envelope->id,
                    'recipient_id' => $recipients[$field['recipient_index']]->id,
                    'type' => $field['type'],
                    'page' => $field['page'],
                    'x' => $field['x'],
                    'y' => $field['y'],
                    'w' => $field['w'],
                    'h' => $field['h'],
                    'required' => $field['required'] ?? true,
                ]);
            }

            return $envelope;
        });

        $this->audit->record($envelope, AuditEvent::ENVELOPE_CREATED, [
            'document' => $document->filename,
            'sha256_original' => $document->sha256_original,
            'recipients' => count($data['recipients']),
            'fields' => count($data['fields']),
        ], request: $request, actor: $request->user()->email);

        return response()->json([
            'envelope' => $envelope->load(['recipients', 'fields']),
        ], 201);
    }

    public function send(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeSender($request, $envelope);

        abort_unless(
            $envelope->status === Envelope::STATUS_DRAFT,
            422,
            'Only draft envelopes can be sent.'
        );

        $envelope->forceFill([
            'status' => Envelope::STATUS_SENT,
            'sent_at' => Carbon::now('UTC'),
        ])->save();

        $this->audit->record($envelope, AuditEvent::ENVELOPE_SENT, [
            'subject' => $envelope->subject,
        ], request: $request, actor: $request->user()->email);

        foreach ($envelope->recipients as $recipient) {
            $token = $this->tokens->issue($recipient);
            $url = $this->tokens->signingUrl($recipient, $token);

            $recipient->forceFill(['status' => Recipient::STATUS_SENT])->save();

            Mail::to($recipient->email)->queue(new SigningInvitation($envelope, $recipient, $url));

            // The URL is deliberately not written to the audit log — the log is
            // shown to every party and printed into the certificate, and it
            // would otherwise hand each signer everyone else's signing link.
            $this->audit->record($envelope, AuditEvent::RECIPIENT_EMAIL_SENT, [
                'to' => $recipient->email,
                'expires_at' => $recipient->token_expires_at?->toIso8601String(),
            ], recipient: $recipient, request: $request, actor: $request->user()->email);
        }

        return response()->json([
            'envelope' => $envelope->fresh()->load(['recipients']),
        ]);
    }

    public function void(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeSender($request, $envelope);
        abort_if($envelope->isTerminal(), 422, 'This envelope is already closed.');

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $envelope->forceFill(['status' => Envelope::STATUS_VOIDED])->save();

        // Every outstanding link dies with the envelope.
        $envelope->recipients()->update([
            'access_token_hash' => DB::raw("md5(random()::text) || md5(random()::text)"),
            'token_expires_at' => Carbon::now('UTC'),
        ]);

        $this->audit->record($envelope, AuditEvent::ENVELOPE_VOIDED, [
            'reason' => $reason,
        ], request: $request, actor: $request->user()->email);

        return response()->json(['envelope' => $envelope->fresh()]);
    }

    public function auditTrail(Request $request, Envelope $envelope): JsonResponse
    {
        $this->authorizeSender($request, $envelope);

        return response()->json([
            'chain' => $this->audit->verifyChain($envelope),
            'events' => $envelope->auditEvents,
        ]);
    }

    public function download(Request $request, Envelope $envelope): StreamedResponse
    {
        $this->authorizeSender($request, $envelope);

        $sealed = $envelope->sealedDocument;
        abort_unless($sealed, 404, 'This envelope has not been sealed yet.');

        return Storage::disk(config('signing.storage_disk'))->download(
            $sealed->storage_key,
            'signed-' . $envelope->document->filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    private function authorizeSender(Request $request, Envelope $envelope): void
    {
        abort_unless($envelope->sender_id === $request->user()->id, 403);
    }
}
