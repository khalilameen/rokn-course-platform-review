export type VideoQuality = 'auto' | '1080p' | '720p' | '480p' | '360p';

export type VideoQualitySources = Partial<
  Record<Exclude<VideoQuality, 'auto'>, string>
>;

export type VideoFitMode = 'cover' | 'contain';

export type AttachmentPlatform = 'computer' | 'mobile' | 'app' | 'file' | 'any';

export interface CourseAttachment {
  id: string;
  title: string;
  url: string;
  fileType?: string;
  fileSize?: string;
  platform: AttachmentPlatform;
}

export interface CourseReel {
  id: string;
  lessonId: string;
  sectionId: string;
  moduleId: string;
  title: string;
  caption: string;
  videoUrl: string;
  /** Optional second CDN/source used only after bounded recovery attempts fail. */
  fallbackVideoUrl?: string;
  /** Direct MP4 renditions. Without these, fixed quality is shown only for adaptive streams. */
  qualitySources?: VideoQualitySources;
  thumbnailUrl?: string;
  durationSeconds?: number;
  availableQualities: VideoQuality[];
  /** Short-lived server decision for this lesson; never persisted as a library URL. */
  playbackSessionId?: string;
  playbackProtocol?: 'hls' | 'dash' | 'mp4' | 'unknown';
  playbackExpiresAt?: string;
  playbackRefreshAfter?: string;
  /** Changes whenever a signed source is re-issued, even if its session is reused. */
  playbackManifestRevision?: number;
  mediaStatus?: 'ready' | 'processing' | 'failed' | 'unknown';
  isPreview: boolean;
  isLocked: boolean;
  isCompleted: boolean;
  reelNumber: number;
}

export type ProjectStatus =
  | 'not_submitted'
  | 'reviewing'
  | 'passed'
  | 'needs_retry';

export interface CourseProject {
  id: string;
  sectionId: string;
  moduleId: string;
  title: string;
  requirements: string;
  status: ProjectStatus;
  isGraduationProject: boolean;
  attachments: CourseAttachment[];
}

export interface CourseLearningModule {
  id: string;
  title: string;
  description?: string;
  order: number;
  isLocked: boolean;
  attachments: CourseAttachment[];
  reels: CourseReel[];
  project?: CourseProject;
}

export interface CourseAttachmentPrompt {
  enabled: boolean;
  atSeconds: number;
  title: string;
  body: string;
  buttonText: string;
  frequency: 'once_per_module';
}

export interface CourseLearningData {
  id: string;
  title: string;
  image?: string | number;
  totalReels: number;
  modules: CourseLearningModule[];
  isDemo?: boolean;
  /** How this learner received access; supplied by the entitlement API. */
  accessType?: string;
  /** Course-chat availability from the entitlement API. */
  chatAvailable?: boolean;
  /** Explicit false keeps certificate generation server- and client-locked. */
  certificateAvailable?: boolean;
  /** Dashboard-controlled discovery prompt; file URLs remain module scoped. */
  attachmentPrompt?: CourseAttachmentPrompt;
}

export type CourseFeedItem =
  | {
      key: string;
      type: 'reel';
      moduleId: string;
      reel: CourseReel;
    }
  | {
      key: string;
      type: 'project';
      moduleId: string;
      project: CourseProject;
    };

export interface SelectedProjectFile {
  uri: string;
  name: string;
  type: string;
  size?: number;
}

export interface ChatMessage {
  id: string;
  role: 'assistant' | 'user';
  text: string;
  createdAt: number;
  pending?: boolean;
}
