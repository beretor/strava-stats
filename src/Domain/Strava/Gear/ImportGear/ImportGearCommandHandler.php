<?php

namespace App\Domain\Strava\Gear\ImportGear;

use App\Domain\Strava\Activity\ReadModel\ActivityDetailsRepository;
use App\Domain\Strava\Gear\Gear;
use App\Domain\Strava\Gear\ReadModel\GearDetailsRepository;
use App\Domain\Strava\Gear\WriteModel\GearRepository;
use App\Domain\Strava\MaxResourceUsageHasBeenReached;
use App\Domain\Strava\Strava;
use App\Domain\Strava\StravaErrorStatusCode;
use App\Infrastructure\Attribute\AsCommandHandler;
use App\Infrastructure\CQRS\CommandHandler\CommandHandler;
use App\Infrastructure\CQRS\DomainCommand;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Sleep;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Lcobucci\Clock\Clock;

#[AsCommandHandler]
final readonly class ImportGearCommandHandler implements CommandHandler
{
    public function __construct(
        private Strava $strava,
        private ActivityDetailsRepository $activityDetailsRepository,
        private GearRepository $gearRepository,
        private GearDetailsRepository $gearDetailsRepository,
        private MaxResourceUsageHasBeenReached $maxResourceUsageHasBeenReached,
        private Clock $clock,
        private Sleep $sleep,
    ) {
    }

    public function handle(DomainCommand $command): void
    {
        assert($command instanceof ImportGear);
        $command->getOutput()->writeln('Importing gear...');

        $gearIds = $this->activityDetailsRepository->findUniqueGearIds();

        foreach ($gearIds as $gearId) {
            try {
                $stravaGear = $this->strava->getGear($gearId);
            } catch (ClientException|RequestException $exception) {
                if (!$exception->getResponse() || !StravaErrorStatusCode::tryFrom(
                    $exception->getResponse()->getStatusCode()
                )) {
                    // Re-throw, we only want to catch supported error codes.
                    throw $exception;
                }
                // This will allow initial imports with a lot of activities to proceed the next day.
                // This occurs when we exceed Strava API rate limits or throws an unexpected error.
                $this->maxResourceUsageHasBeenReached->markAsReached();
                $command->getOutput()->writeln('<error>You probably reached Strava API rate limits. You will need to import the rest of your activities tomorrow</error>');

                return;
            }

            try {
                $gear = $this->gearDetailsRepository->find($gearId);
                $gear
                    ->updateDistance($stravaGear['distance'], $stravaGear['converted_distance'])
                ->updateIsRetired($stravaGear['retired'] ?? false);
                $this->gearRepository->update($gear);
            } catch (EntityNotFound) {
                $gear = Gear::create(
                    gearId: $gearId,
                    data: $stravaGear,
                    distanceInMeter: $stravaGear['distance'],
                    createdOn: SerializableDateTime::fromDateTimeImmutable($this->clock->now()),
                );
                $this->gearRepository->add($gear);
            }
            $command->getOutput()->writeln(sprintf('  => Imported/updated gear "%s"', $gear->getName()));
            // Try to avoid Strava rate limits.
            $this->sleep->sweetDreams(10);
        }
    }
}
