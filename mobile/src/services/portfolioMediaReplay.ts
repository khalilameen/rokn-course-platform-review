import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../constants/helpers';
import {appendPortfolioMedia} from './api/profile';
import {
  completePortfolioMediaUpload,
  discardPortfolioMediaUploads,
  listPortfolioMediaUploads,
} from './portfolioMediaOutbox';

export type PortfolioMediaReplayResult = {
  attempted: number;
  completed: number;
  completedProjectIds: string[];
  completionRevision: number;
};

const flights = new Map<string, Promise<PortfolioMediaReplayResult>>();
const completionRevisions = new Map<string, number>();

const responseStatus = (error: unknown) =>
  Number(
    (error as {status?: unknown; response?: {status?: unknown}})?.status ??
      (error as {response?: {status?: unknown}})?.response?.status ??
      0,
  );

/**
 * Replays one account's durable media queue independently from screen data
 * hydration. Every caller for that account joins the same flight, so startup,
 * foreground and Gallery focus cannot upload the same entry concurrently.
 */
export const replayPendingPortfolioMediaUploads = async () => {
  const boundary = await captureAccountSessionBoundary();
  const flightKey = `${boundary.scope}:${boundary.epoch}`;
  const existing = flights.get(flightKey);
  if (existing) return existing;

  const flight = (async (): Promise<PortfolioMediaReplayResult> => {
    assertAccountSessionBoundary(boundary);
    const pending = await listPortfolioMediaUploads();
    const failedProjects = new Set<string>();
    const completedProjects = new Set<string>();
    let attempted = 0;
    let completed = 0;

    for (const entry of pending) {
      assertAccountSessionBoundary(boundary);
      if (failedProjects.has(entry.projectId)) continue;
      attempted += 1;
      try {
        await appendPortfolioMedia(
          entry.projectId,
          entry.file,
          entry.clientRequestId,
        );
        assertAccountSessionBoundary(boundary);
        await completePortfolioMediaUpload(entry);
        completed += 1;
        completedProjects.add(entry.projectId);
      } catch (error: unknown) {
        failedProjects.add(entry.projectId);
        if (responseStatus(error) === 404) {
          await discardPortfolioMediaUploads(entry.projectId).catch(
            () => undefined,
          );
        }
      }
    }

    const completionRevision = completed
      ? (completionRevisions.get(flightKey) || 0) + 1
      : completionRevisions.get(flightKey) || 0;
    if (completed) completionRevisions.set(flightKey, completionRevision);
    return {
      attempted,
      completed,
      completedProjectIds: [...completedProjects],
      completionRevision,
    };
  })().finally(() => {
    if (flights.get(flightKey) === flight) flights.delete(flightKey);
  });
  flights.set(flightKey, flight);
  return flight;
};

export const resetPortfolioMediaReplayForTests = () => {
  flights.clear();
  completionRevisions.clear();
};
