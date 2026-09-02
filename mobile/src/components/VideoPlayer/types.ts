export type VideoQuality = 'auto' | '1080p' | '720p' | '480p' | '360p';

export type VideoQualitySources = Partial<
  Record<Exclude<VideoQuality, 'auto'>, string>
>;

export type AttachmentPlatform = 'computer' | 'mobile' | 'app' | 'file' | 'any';

export interface CourseAttachment {
  id: string;
  title: string;
  url: string;
  fileType?: string;
  mimeType?: string;
  fileSize?: string;
  fileSizeBytes?: number;
  downloadVersion?: string;
  external?: boolean;
  platform: AttachmentPlatform;
  courseId?: string;
  moduleId?: string;
  temporary?: boolean;
  expiresAt?: string;
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

export interface ProjectFeedbackMessage {
  id: string;
  clientRequestId?: string;
  role: 'assistant' | 'user';
  status: 'queued' | 'sent' | 'streaming' | 'completed' | 'failed' | 'cancelled';
  errorCode?: string;
  text?: string;
  createdAt?: string;
  attachments?: ChatAttachmentDraft[];
}

export interface ProjectFeedbackThread {
  id: string;
  feedbackLevel: 'report' | 'enhanced';
  canReply: boolean;
  status: string;
  remainingMessages: number;
  messages: ProjectFeedbackMessage[];
  attachmentsEnabled?: boolean;
  attachmentMaxFiles?: number;
}

export interface CourseProject {
  id: string;
  sectionId: string;
  moduleId: string;
  title: string;
  requirements: string;
  status: ProjectStatus;
  isGraduationProject: boolean;
  attachments: CourseAttachment[];
  feedbackLevel?: 'pass_only' | 'report' | 'enhanced';
  reportEnabled?: boolean;
  feedbackThread?: ProjectFeedbackThread;
  submissionMaxFiles?: number;
  submissionAllowedMimeTypes?: string[];
  submissionAttachments?: ChatAttachmentDraft[];
}

export interface CourseQuiz {
  id: string;
  sectionId: string;
  moduleId: string;
  title: string;
  description?: string;
  timeMinutes?: number;
  isLocked: boolean;
  passed: boolean;
  scorePercentage?: number;
}

export interface CourseLearningModule {
  id: string;
  title: string;
  /** Legacy/demo metadata; production modules intentionally render the title only. */
  description?: string;
  order: number;
  isLocked: boolean;
  attachments: CourseAttachment[];
  reels: CourseReel[];
  quizzes?: CourseQuiz[];
  project?: CourseProject;
}

export interface CourseAttachmentPrompt {
  enabled: boolean;
  atSeconds: number;
  title: string;
  body: string;
  buttonText: string;
  frequency: 'once_per_course' | 'once_per_module';
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
  chatAttachmentsEnabled?: boolean;
  chatAttachmentMaxFiles?: number;
  /** Explicit false keeps certificate generation server- and client-locked. */
  certificateAvailable?: boolean;
  /** The purchased/granted plan includes certificate issuance after completion. */
  certificateIncluded?: boolean;
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
    }
  | {
      key: string;
      type: 'quiz';
      moduleId: string;
      quiz: CourseQuiz;
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
  clientRequestId?: string;
  deliveryStatus?:
    | 'queued'
    | 'sent'
    | 'streaming'
    | 'completed'
    | 'failed'
    | 'cancelled';
  errorCode?: string;
  /** Failed/system UI copy is visible but never becomes model context. */
  contextEligible?: boolean;
  attachments?: ChatAttachmentDraft[];
}

export interface ChatAttachmentDraft {
  uri: string;
  name: string;
  type: string;
  size?: number;
  uploadId: string;
  serverId?: string;
  downloadUrl?: string;
  downloadExpiresAt?: string;
}
