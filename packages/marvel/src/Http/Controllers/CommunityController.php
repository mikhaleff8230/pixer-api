<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Community;
use Marvel\Http\Resources\PlaceResource;

class CommunityController extends CoreController
{
    public function index(Request $request)
    {
        $query = Community::query()
            ->where('status', 'active')
            ->withCount(['members', 'places'])
            ->orderByDesc('is_system')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $communities = $query->get();
        $profileId = optional($request->user('sanctum'))->profile?->id;
        $communities->each(function (Community $community) use ($profileId) {
            $community->setAttribute('is_joined', $profileId
                ? $community->members()->where('user_profiles.id', $profileId)->wherePivot('status', 'active')->exists()
                : false);
        });

        return $communities;
    }

    public function show(Request $request, string $slug)
    {
        $community = Community::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with('owner.customer:id,name')
            ->withCount(['members', 'places'])
            ->firstOrFail();
        $profileId = optional($request->user('sanctum'))->profile?->id;
        $community->setAttribute('is_joined', $profileId
            ? $community->members()->where('user_profiles.id', $profileId)->wherePivot('status', 'active')->exists()
            : false);

        return $community;
    }

    private function resolveCommunity(Community|string|int $community): Community
    {
        if ($community instanceof Community && $community->exists && $community->getKey()) {
            return $community;
        }

        $communityId = $community instanceof Community
            ? request()->route('community')
            : $community;

        return Community::query()->where('status', 'active')->findOrFail($communityId);
    }

    public function join(Request $request, Community|string|int $community)
    {
        $community = $this->resolveCommunity($community);
        $profile = $request->user()->profile;
        abort_unless($profile, 422, 'Social profile is required');
        $community->members()->syncWithoutDetaching([
            $profile->id => ['role' => 'member', 'status' => 'active', 'joined_at' => now()],
        ]);
        $community->update(['members_count' => $community->members()->wherePivot('status', 'active')->count()]);

        return response()->json(['joined' => true, 'members_count' => $community->members_count]);
    }

    public function toggleMembership(Request $request, Community|string|int $community)
    {
        $community = $this->resolveCommunity($community);
        $profile = $request->user()->profile;
        abort_unless($profile, 422, 'Social profile is required');
        $joined = $community->members()->where('user_profiles.id', $profile->id)->exists();

        if ($joined) {
            $membership = $community->members()->where('user_profiles.id', $profile->id)->first();
            abort_if($membership?->pivot?->role === 'owner', 422, 'Community owner cannot leave');
            $community->members()->detach($profile->id);
        } else {
            $community->members()->attach($profile->id, [
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $community->update(['members_count' => $community->members()->wherePivot('status', 'active')->count()]);

        return response()->json([
            'joined' => !$joined,
            'members_count' => $community->members_count,
        ]);
    }

    public function leave(Request $request, Community|string|int $community)
    {
        $community = $this->resolveCommunity($community);
        $profile = $request->user()->profile;
        abort_unless($profile, 422, 'Social profile is required');
        $membership = $community->members()->where('user_profiles.id', $profile->id)->first();
        abort_if($membership?->pivot?->role === 'owner', 422, 'Community owner cannot leave');
        $community->members()->detach($profile->id);
        $community->update(['members_count' => $community->members()->wherePivot('status', 'active')->count()]);

        return response()->json(['joined' => false, 'members_count' => $community->members_count]);
    }

    public function places(Request $request, Community|string|int $community)
    {
        $community = $this->resolveCommunity($community);
        $places = $community->places()
            ->with(['images', 'videos', 'hashtags', 'user.profile', 'community', 'likes', 'products', 'wishlists'])
            ->latest()
            ->paginate(min((int) $request->get('limit', 20), 50));

        return PlaceResource::collection($places);
    }
}
