<?php

namespace App\Events;

use App\Models\Course;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

class CourseCompleted
{
    use Dispatchable, InteractsWithSockets;

    public ?int $userId = null;
    public ?int $courseId = null;

    /** Rolling-deploy compatibility for events emitted by the previous release. */
    public User|int|null $user = null;
    public Course|int|null $course = null;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(User|int $user, Course|int $course)
    {
        $this->userId = $user instanceof User ? (int) $user->getKey() : $user;
        $this->courseId = $course instanceof Course ? (int) $course->getKey() : $course;
    }

    public function resolvedUserId(): int
    {
        return (int) ($this->userId
            ?: ($this->user instanceof User ? $this->user->getKey() : $this->user));
    }

    public function resolvedCourseId(): int
    {
        return (int) ($this->courseId
            ?: ($this->course instanceof Course ? $this->course->getKey() : $this->course));
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
