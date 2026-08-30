<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invites — WhenTheFox</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <h1>Invites</h1>

        <form method="POST" action="{{ route('invites.store') }}">
            @csrf
            <label for="max_uses">Max uses (blank = unlimited)</label>
            <input type="number" id="max_uses" name="max_uses" min="1">

            <label for="expires_in_days">Expires in days (blank = never)</label>
            <input type="number" id="expires_in_days" name="expires_in_days" min="1">

            <button type="submit">Create invite</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Uses</th>
                    <th>Max uses</th>
                    <th>Expires</th>
                    <th>Source</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invites as $invite)
                    <tr>
                        <td><a href="{{ route('register') }}?code={{ $invite->code }}">{{ $invite->code }}</a></td>
                        <td>{{ $invite->redemptions->count() }}</td>
                        <td>{{ $invite->max_uses ?? '∞' }}</td>
                        <td>{{ $invite->expires_at?->toDateString() ?? 'never' }}</td>
                        <td>{{ $invite->source_share_link_id ? 'share-link CTA' : 'manual' }}</td>
                        <td>
                            <form method="POST" action="{{ route('invites.destroy', $invite) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit">Revoke</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </main>
</body>
</html>
