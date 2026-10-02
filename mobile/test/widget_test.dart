import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:greenify_mobile/main.dart';

void main() {
  testWidgets('Greenify opens the login screen', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: GreenifyApp()));
    await tester.pumpAndSettle();

    expect(find.text('Citizen'), findsOneWidget);
    expect(find.text('Recycler'), findsOneWidget);
    expect(find.text('Admin'), findsOneWidget);
    expect(find.text('Log In'), findsOneWidget);
  });
}
