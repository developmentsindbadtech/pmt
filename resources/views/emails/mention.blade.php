<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You were mentioned</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.5; color: #1f2937; background-color: #f9fafb; margin: 0; padding: 0;">
    <table role="presentation" style="width: 100%; border-collapse: collapse; background-color: #f9fafb;">
        <tr>
            <td style="padding: 32px 16px;">
                <table role="presentation" style="max-width: 560px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden;">
                    <!-- Email Header -->
                    <tr>
                        <td style="padding: 20px 24px; text-align: center; background-color: #ffffff; border-bottom: 1px solid #e5e7eb;">
                            <h1 style="margin: 0; font-size: 20px; font-weight: 600; color: #111827; line-height: 1.3;">
                                Sindbad.Tech Project Management Tool
                            </h1>
                        </td>
                    </tr>
                    
                    <!-- Notification Header -->
                    <tr>
                        <td style="padding: 24px 24px 20px; background-color: #ffffff; border-bottom: 1px solid #e5e7eb;">
                            <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827; line-height: 1.3;">
                                You were mentioned
                            </h2>
                            <p style="margin: 6px 0 0; font-size: 13px; color: #6b7280;">
                                {{ $mentionedBy->name }} mentioned you in {{ $item->isBug() ? 'Bug' : 'Task' }} #{{ $item->number }}
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 20px 24px;">
                            <!-- Task Info -->
                            <div style="margin-bottom: 16px;">
                                <span style="display: inline-block; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">{{ $item->isBug() ? 'Bug' : 'Task' }}</span>
                                <h2 style="margin: 0 0 4px; font-size: 16px; font-weight: 600; color: #111827; line-height: 1.4;">
                                    {{ $item->name }}
                                </h2>
                                <p style="margin: 0; font-size: 12px; color: #6b7280;">
                                    Board: {{ $board->name }}
                                </p>
                            </div>

                            <!-- Comment/Description -->
                            <div style="padding-top: 16px; border-top: 1px solid #e5e7eb;">
                                <span style="display: block; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                                    {{ $contentType === 'comment' ? 'Comment' : ($contentType === 'description' ? 'Description' : 'Repro Steps') }}
                                </span>
                                <p style="margin: 0; font-size: 14px; color: #374151; line-height: 1.6; white-space: pre-wrap;">{{ $preview }}</p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Action Button -->
                    <tr>
                        <td style="padding: 0 24px 24px; text-align: center;">
                            <a href="{{ $itemUrl }}" 
                               style="display: inline-block; background-color: #3b82f6; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 500; font-size: 14px; line-height: 1.5;">
                                View {{ $item->isBug() ? 'Bug' : 'Task' }}
                            </a>
                        </td>
                    </tr>
                </table>
                
                <!-- Footer -->
                <table role="presentation" style="max-width: 560px; margin: 24px auto 0;">
                    <tr>
                        <td style="text-align: center; padding: 0 16px;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; line-height: 1.5;">
                                This email was sent because you were mentioned in a {{ $contentType }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
