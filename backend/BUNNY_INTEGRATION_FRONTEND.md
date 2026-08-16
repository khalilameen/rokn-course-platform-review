# Bunny.net Video Integration - Frontend Updates

## Overview

The backend now supports optional Bunny.net video streaming integration. When enabled by the tenant, lessons can have videos hosted on Bunny.net instead of YouTube links.

## API Response Changes

### Lesson Content Structure

When fetching course details or lesson data, the API now returns additional fields for video handling:

```json
{
  "sections": [
    {
      "type": "lesson",
      "content": {
        "id": 123,
        "title": "Lesson Title",
        "description": "Lesson description",
        "video_source_type": "youtube",    // NEW: "youtube" or "bunny"
        "video_link": "https://youtube.com/watch?v=...",  // Only for YouTube
        "bunny_video_url": null,            // NEW: Signed embed URL for Bunny
        "bunny_video_expires_at": null,     // NEW: ISO 8601 expiration timestamp
        "file_link1": "...",
        "file_link2": "...",
        "is_opened": true
      }
    }
  ]
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `video_source_type` | string | Either `"youtube"` or `"bunny"` |
| `video_link` | string\|null | YouTube video URL (only when source is youtube) |
| `bunny_video_url` | string\|null | Signed Bunny embed URL (only when source is bunny) |
| `bunny_video_expires_at` | string\|null | ISO 8601 timestamp when the signed URL expires |

## Frontend Implementation Guide

### 1. Video Player Component

Update your video player component to handle both sources:

```tsx
// React/TypeScript example
interface LessonContent {
  id: number;
  title: string;
  video_source_type: 'youtube' | 'bunny';
  video_link: string | null;
  bunny_video_url: string | null;
  bunny_video_expires_at: string | null;
}

const VideoPlayer: React.FC<{ lesson: LessonContent }> = ({ lesson }) => {
  if (lesson.video_source_type === 'bunny' && lesson.bunny_video_url) {
    return (
      <div className="video-container">
        <iframe
          src={lesson.bunny_video_url}
          loading="lazy"
          style={{ border: 'none', width: '100%', aspectRatio: '16/9' }}
          allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture"
          allowFullScreen
        />
      </div>
    );
  }

  if (lesson.video_source_type === 'youtube' && lesson.video_link) {
    // Extract YouTube video ID and render YouTube embed
    const videoId = extractYouTubeId(lesson.video_link);
    return (
      <div className="video-container">
        <iframe
          src={`https://www.youtube.com/embed/${videoId}`}
          style={{ border: 'none', width: '100%', aspectRatio: '16/9' }}
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowFullScreen
        />
      </div>
    );
  }

  return <div>No video available</div>;
};
```

### 2. Handling Expired URLs

Bunny signed URLs expire after 2 hours. Implement URL refresh logic:

```tsx
const useBunnyVideoUrl = (lesson: LessonContent, refetchLesson: () => void) => {
  const [isExpired, setIsExpired] = useState(false);

  useEffect(() => {
    if (lesson.video_source_type !== 'bunny' || !lesson.bunny_video_expires_at) {
      return;
    }

    const expiresAt = new Date(lesson.bunny_video_expires_at);
    const now = new Date();
    
    // Check if already expired
    if (expiresAt <= now) {
      setIsExpired(true);
      refetchLesson();
      return;
    }

    // Set up timer to refresh before expiration (5 minutes buffer)
    const timeUntilExpiry = expiresAt.getTime() - now.getTime() - (5 * 60 * 1000);
    
    if (timeUntilExpiry > 0) {
      const timer = setTimeout(() => {
        setIsExpired(true);
        refetchLesson();
      }, timeUntilExpiry);

      return () => clearTimeout(timer);
    }
  }, [lesson.bunny_video_expires_at]);

  return { isExpired };
};
```

### 3. CSS Styling

```css
.video-container {
  position: relative;
  width: 100%;
  max-width: 100%;
  border-radius: 8px;
  overflow: hidden;
  background: #000;
}

.video-container iframe {
  width: 100%;
  aspect-ratio: 16 / 9;
}

/* Loading state */
.video-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 360px;
  background: #1a1a1a;
  color: #fff;
}
```

## Bunny Player Features

When using Bunny.net, the embedded player includes:

- Adaptive bitrate streaming (HLS)
- Multiple quality options (auto-selected)
- Playback speed controls
- Fullscreen support
- Picture-in-picture support
- Closed captions (if configured in Bunny dashboard)
- Hotkeys for seeking and volume

## Error Handling

```tsx
const VideoPlayer: React.FC<{ lesson: LessonContent }> = ({ lesson }) => {
  const [error, setError] = useState<string | null>(null);

  const handleIframeError = () => {
    setError('Failed to load video. Please try refreshing the page.');
  };

  if (error) {
    return (
      <div className="video-error">
        <p>{error}</p>
        <button onClick={() => window.location.reload()}>
          Refresh Page
        </button>
      </div>
    );
  }

  // ... rest of component
};
```

## Migration Notes

1. **Backward Compatibility**: Existing lessons with YouTube links will continue to work. The `video_source_type` defaults to `"youtube"`.

2. **Feature Detection**: Check if `video_source_type` field exists for backward compatibility with older API responses.

3. **No Changes Required**: If the tenant hasn't enabled Bunny.net, all lessons will have `video_source_type: "youtube"` and the frontend behavior remains unchanged.

## Testing Checklist

- [ ] YouTube videos play correctly
- [ ] Bunny videos load and play correctly
- [ ] Expired URL triggers refetch
- [ ] Error handling for failed video loads
- [ ] Responsive video container
- [ ] Fullscreen works on both video types
- [ ] Mobile playback works correctly

