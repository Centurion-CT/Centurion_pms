// Every word the app shows. Plain, short, sentence case. No em dashes.

class S {
  S._();

  static const appName = 'Centurion Staff';
  static const hotelName = 'Centurion Apartments';

  // Sign in
  static const signInTitle = 'Sign in';
  static const signInBody = 'Use the phone number your manager saved for you.';
  static const phoneLabel = 'Phone number';
  static const phoneHint = '0803 123 4567';
  static const phoneTooShort = 'Enter your full phone number.';
  static const next = 'Next';
  static const hotelAddress = 'Hotel address';
  static const hotelAddressHint = 'staff.centurionapartments.com';
  static const hotelAddressMissing = 'Enter the hotel address first.';
  static const pinTitle = 'Enter your PIN';
  static String pinFor(String phone) => 'For $phone';
  static const signIn = 'Sign in';
  static const deleteDigit = 'Delete last digit';
  static const wrongPin = "That phone number and PIN don't match.";
  static const tooManyAttempts = 'Too many tries. Wait a few minutes and try again.';
  static const cantReach = "Can't reach the hotel. Check your connection and try again.";
  static const suspended = 'Your account is paused. Ask your manager.';
  static const somethingWrong = 'Something went wrong. Try again.';
  static const pickHotelTitle = 'Pick your hotel';
  static const pickHotelBody = 'Your number works at more than one place.';
  static const notForYouTitle = 'This app is for housekeepers and porters';
  static const notForYouBody =
      'This app is for housekeepers and porters. Use the Centurion dashboard on the web instead.';
  static const backToSignIn = 'Back to sign in';
  static const back = 'Back';

  // Tabs
  static const tabMyRooms = 'My rooms';
  static const tabToCheck = 'To check';
  static const tabSupplies = 'Supplies';
  static const tabMe = 'Me';

  // Greeting
  static String greeting(int hour, String name) {
    final part = hour < 12 ? 'morning' : (hour < 17 ? 'afternoon' : 'evening');
    return 'Good $part, $name';
  }

  static String eyebrow(String date, String shift) => [date, shift].where((s) => s.isNotEmpty).join(' · ');

  // My rooms
  static String roomsSummary(int toClean, int withPorter) {
    if (toClean == 0 && withPorter == 0) return 'All done for now.';
    final a = '$toClean ${toClean == 1 ? 'room' : 'rooms'} to clean';
    if (withPorter == 0) return a;
    return '$a, $withPorter with the porter';
  }

  static const today = 'Today';
  static String ofRoomsReady(int total) => 'of $total ${total == 1 ? 'room' : 'rooms'} ready';
  static String ofRoomsChecked(int total) => 'of $total ${total == 1 ? 'room' : 'rooms'} checked';
  static const noRooms = 'No rooms for you right now. Pull down to refresh.';
  static const doFirst = 'Do first';
  static const chipToClean = 'To clean';
  static const chipCleaning = 'Cleaning';
  static const chipSentBack = 'Sent back';
  static const chipWithPorter = 'With porter';
  static const chipReady = 'Ready';
  static String porterSentBack(String reasons) => 'Porter sent it back: $reasons';
  static String guestArriving(String time) => 'Guest arriving $time';
  static const guestArrivingToday = 'Guest arriving today';
  static const waitingForPorter = 'Waiting for porter check';
  static const readyForGuest = 'Ready for the guest';
  static String checkedOut(String time) => 'Checked out $time';
  static String roomTitle(String number) => 'Room $number';
  static String openRoom(String number) => 'Open room $number';

  // Cleaning
  static String doneOf(int done, int total) => '$done of $total';
  static const doneSuffix = ' done';
  static const doneSendToPorter = 'Done, send to porter';
  static const anythingBroken = 'Anything broken or missing?';
  static const anythingBrokenBody = 'Tell the porter. You can leave this empty.';
  static const noteHint = 'For example, bedside lamp bulb is out';
  static String itemsNotTicked(int n) => '$n ${n == 1 ? 'item' : 'items'} not ticked';
  static const sendToPorter = 'Send to porter';
  static String sentToPorter(String number) => 'Room $number sent to the porter.';
  static String sentBackBy(String by, String time) => 'Sent back by $by${time.isEmpty ? '' : ', $time'}';
  static const withPorterBanner = 'This room is with the porter now.';
  static const readyBanner = 'This room is ready for the guest.';
  static String tick(String label) => 'Tick $label';
  static String untick(String label) => 'Untick $label';

  // Porter
  static String roomsToCheck(int n) => n == 0 ? 'Nothing waiting.' : '$n ${n == 1 ? 'room' : 'rooms'} to check';
  static const nothingToCheck = 'Nothing to check right now.';
  static String cleanedBy(String name, String time) => 'Cleaned by $name${time.isEmpty ? '' : ', $time'}';
  static String cleanedByTitle(String name) => 'Cleaned by $name';
  static String tookMinutes(int m) => 'took $m min';
  static const score = 'score';
  static const checkThisRoom = 'Check this room';
  static String noted(String name) => '$name noted';
  static const lookAtThese = 'Look at these';
  static const sendBack = 'Send back';
  static const roomIsReady = 'Room is ready';
  static String roomReady(String number) => 'Room $number is ready.';
  static const whySendBack = 'What needs fixing?';
  static const whySendBackBody = 'Tap everything that applies.';
  static const sendBackNoteHint = 'Anything else to say (optional)';
  static String sendBackTo(String name) => 'Send back to $name';
  static String roomSentBack(String number) => 'Room $number sent back.';
  static const reasonTowels = 'Towels';
  static const reasonBathroom = 'Bathroom';
  static const reasonBed = 'Bed';
  static const reasonFloor = 'Floor';
  static const reasonSmell = 'Smell';
  static const reasonMissing = 'Something missing';
  static const reasonOther = 'Other';
  static const notTicked = 'Not ticked';

  // Supplies
  static const housekeepingStore = 'Housekeeping store';
  static const supplies = 'Supplies';
  static const suppliesBody = 'Ask the store for what you need today.';
  static const askedEarlier = 'Asked earlier';
  static const askTheStore = 'Ask the store';
  static const pickSomething = 'Tap + on what you need first.';
  static const sentToStore = 'Sent to the store.';
  static const noProducts = 'Nothing to ask for yet. Pull down to refresh.';
  static const statusWaiting = 'waiting';
  static const statusReadyToCollect = 'ready to collect';
  static const statusDeclined = 'declined';
  static const statusCancelled = 'cancelled';
  static String approvedBy(String name) => 'approved by $name, ready to collect';
  static const yesterday = 'Yesterday';
  static String todayAt(String time) => 'Today $time';
  static String less(String name) => 'Fewer $name';
  static String more(String name) => 'More $name';

  // Me
  static const housekeeper = 'Housekeeper';
  static const porter = 'Porter';
  static const thisWeek = 'This week';
  static const roomsCleaned = 'Rooms cleaned';
  static const roomsChecked = 'Rooms checked';
  static const avgTime = 'Average time';
  static const sentBackStat = 'Sent back';
  static String minutes(int m) => '$m min';
  static const signOut = 'Sign out';
  static const signOutPendingTitle = 'Some changes have not sent yet';
  static String signOutPendingBody(int n) =>
      '$n ${n == 1 ? 'change is' : 'changes are'} still on this phone. If you sign out now they will be lost.';
  static const signOutAnyway = 'Sign out anyway';
  static const stay = 'Stay signed in';
  static String version(String v) => 'Version $v';

  // Offline + sync
  static const offline = "Offline. Your changes will send when you're back online.";
  static const sending = 'Sending your changes.';
  static const refreshFailed = "Couldn't refresh. Showing what's saved on this phone.";
  static const loadFailed = "Couldn't load this. Check your connection and pull down to try again.";
  static const tryAgain = 'Try again';
  static const signedOutByServer = 'You were signed out. Sign in again to continue.';
  static String alreadyWithPorter(String label) => '$label was already sent to the porter.';
  static String alreadyChecked(String label) => '$label was already checked.';
  static String movedOn(String label) => '$label has moved on. Your list is up to date now.';
  static String notSaved(String label, String detail) => '$label: $detail';
  static const suppliesNotSent = "Your supplies request didn't go through.";
}
