<?php
declare(strict_types=1);

namespace CertificateGenerator\Email\Transport;

interface TransportInterface {
	public function send( string $to, string $subject, string $body, array $headers, array $attachments ): bool;
	public function get_last_error(): string;
}
