/**
 * User avatar and avatar stack.
 *
 * UserAvatar renders the initial of a user's display name in a colored
 * circle. Colors come from a fixed ten-color palette assigned by each
 * participant's position in the session list (current user first, then
 * order of first contribution), so a user keeps the same color across
 * the whole session. Hovering the avatar opens a small popup below it
 * with the full name.
 *
 * AvatarStack renders session participants as an overlapping row, the
 * way GitHub stacks contributor avatars: collapsed by default, sliding
 * apart when the stack is hovered so every face is visible without the
 * row permanently taking space. The first name (the current user) sits
 * on top of the stack.
 */

import { HoverCard } from "radix-ui";

/** Fixed palette assigned to participants by order of contribution. */
const AVATAR_COLORS = [
  "bg-blue-600",
  "bg-emerald-600",
  "bg-purple-600",
  "bg-rose-600",
  "bg-amber-600",
  "bg-teal-600",
  "bg-indigo-600",
  "bg-pink-600",
  "bg-cyan-700",
  "bg-orange-600",
];

/**
 * Picks the palette color for a participant's position in the session
 * list (cycling past ten). Unknown participants (negative index) get a
 * neutral gray.
 */
export function avatarColorClass(index: number): string {
  if (index < 0) {
    return "bg-gray-400";
  }
  return AVATAR_COLORS[index % AVATAR_COLORS.length] ?? "bg-gray-400";
}

export interface UserAvatarProps {
  /** The user's display name; drives the initial and the popup. */
  name: string;
  /** Background color class, usually from avatarColorClass(). */
  colorClass?: string;
  /** Visual size: md matches a single-line chat bubble. */
  size?: "sm" | "md";
}

/** Colored initial circle with a hover popup showing the full name. */
export function UserAvatar({
  name,
  colorClass = "bg-gray-400",
  size = "md",
}: UserAvatarProps) {
  const initial = (name.trim().charAt(0) || "U").toUpperCase();
  const sizeClasses = size === "sm" ? "h-8 w-8 text-xs" : "h-10 w-10 text-base";

  return (
    <HoverCard.Root openDelay={150} closeDelay={100}>
      <HoverCard.Trigger asChild>
        <div
          className={`flex shrink-0 cursor-pointer items-center justify-center rounded-full font-medium text-white ${sizeClasses} ${colorClass}`}
        >
          {initial}
        </div>
      </HoverCard.Trigger>

      {/* Full name popup below the avatar. The data-ai-app scope lives on
          an inner wrapper so the scoped reset does not disturb the Radix
          positioning of the portaled content. */}
      <HoverCard.Portal>
        <HoverCard.Content side="bottom" sideOffset={6} className="z-50">
          <div
            data-ai-app=""
            className="h-auto rounded-md bg-gray-900 px-2 py-1 text-xs text-white shadow-md"
          >
            {name}
          </div>
        </HoverCard.Content>
      </HoverCard.Portal>
    </HoverCard.Root>
  );
}

/** One entry of the avatar stack. */
export interface AvatarStackItem {
  /** The participant's display name. */
  name: string;
  /** Background color class, usually from avatarColorClass(). */
  colorClass: string;
}

export interface AvatarStackProps {
  /** Participants in display order; the first one renders on top. */
  items: AvatarStackItem[];
}

/** Overlapping avatar row that slides apart on hover, GitHub style. */
export function AvatarStack({ items }: AvatarStackProps) {
  return (
    <div className="group/stack flex items-center">
      {items.map((item, index) => (
        <div
          key={item.name}
          className={`relative rounded-full ring-2 ring-white transition-[margin] duration-200 ${
            index > 0 ? "-ml-3 group-hover/stack:ml-1" : ""
          }`}
          style={{ zIndex: items.length - index }}
        >
          <UserAvatar name={item.name} colorClass={item.colorClass} size="sm" />
        </div>
      ))}
    </div>
  );
}
