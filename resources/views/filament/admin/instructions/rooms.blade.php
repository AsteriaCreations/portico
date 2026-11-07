<x-screen-instructions :title="__('How to use Rooms')">
    <p>{{ __('The rooms a member can book for a private rental at the Check-In Desk, and what each one costs.') }}</p>
    <p>{!! __('The renter pays the <strong>Hourly rate</strong> for every hour booked. The room price covers <strong>People included</strong>, the renter among them; each person past that adds the <strong>Extra-person fee</strong>, either for every hour booked or once.') !!}</p>
    <p>{!! __('<strong>Minimum hours</strong> and <strong>Maximum hours</strong> limit how long the desk can book the room for. A booking keeps the prices it was made at, so changing a room here never changes a booking already made.') !!}</p>
    <p>{!! __('To stop new bookings for a room, turn off <strong>Active</strong> rather than deleting it.') !!}</p>
</x-screen-instructions>
