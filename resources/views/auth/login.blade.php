<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Log in — WhenTheFox</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <h1>Log in</h1>

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
            </div>

            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div>
                <label><input type="checkbox" name="remember"> Remember me</label>
            </div>

            <button type="submit">Log in</button>
        </form>

        <p><a href="{{ route('register') }}">Have an invite code? Create an account</a></p>
    </main>
</body>
</html>
