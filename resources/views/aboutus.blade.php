<html>
<head>
    <title>About Us</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('assets/login/mbs.jpg') }}">
    @vite('resources/css/aboutus.css')
</head>
<body>
    <h1>About Us</h1>
    <h2>Our Mission</h2>
    <p>Our mission is to deliver high-quality products that create value for our customers.</p>
    <p>Welcome to our company. We are dedicated to providing the best services to our clients.</p>
    <h2>Name: {{ $name }}</h2>
    <h2>Email: {{ $email }}</h2>
</body>
</html> 