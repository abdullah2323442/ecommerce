<?php

namespace App\Services\Couriers;

/** One courier company the shop can book parcels with. */
interface CourierProvider
{
    /** The key used in the Connections screen and on a courier: steadfast, pathao. */
    public function key(): string;

    /**
     * Book one parcel.
     *
     * Companies that need more than a name and an address read the extra keys they use: district, area, weight (kg), note, and the city_id and zone_id staff picked.
     *
     * @param  array{invoice: string, name: string, phone: string, address: string, cod: float}  $parcel
     * @return array{consignment_id: string, tracking_code: ?string, status: string}
     *
     * @throws CourierException
     */
    public function book(array $parcel): array;

    /** Where the parcel is now, as the company's own word. @throws CourierException */
    public function status(string $consignmentId): string;

    /** Is the login good? A cheap call that changes nothing. @throws CourierException */
    public function check(): void;
}
