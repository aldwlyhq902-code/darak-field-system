/// What a successful login must contain before the app adopts it.
class LoginSession {
  const LoginSession({required this.token, required this.userId, this.name});

  final String token;
  final int userId;
  final String? name;

  /// Null for anything malformed. A 200 without a token used to be cast
  /// straight to String: the TypeError escaped the error handling and left the
  /// sign-in button spinning forever.
  static LoginSession? parse(Map<String, dynamic> response) {
    final token = response['token'];
    final user = response['user'];
    if (token is! String || token.isEmpty || user is! Map) return null;

    final id = user['id'];
    final userId = id is num ? id.toInt() : int.tryParse('$id');
    if (userId == null) return null;

    final name = user['name'];
    return LoginSession(
      token: token,
      userId: userId,
      name: name is String ? name : null,
    );
  }
}
